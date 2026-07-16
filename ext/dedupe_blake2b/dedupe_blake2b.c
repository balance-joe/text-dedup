#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"
#include "php_dedupe_blake2b.h"
#include "blake2b_ref.h"
#include <stdint.h>
#include <stdio.h>
#include <string.h>

#define DEDUPE_MINHASH_PERMUTATIONS 128

static uint64_t minhash_a[DEDUPE_MINHASH_PERMUTATIONS];
static uint64_t minhash_b[DEDUPE_MINHASH_PERMUTATIONS];

static uint64_t load_be64(const unsigned char *value)
{
    uint64_t result = 0;
    int index;
    for (index = 0; index < 8; ++index) result = (result << 8) | value[index];
    return result;
}

static void store_be64(unsigned char *output, uint64_t value)
{
    int index;
    for (index = 7; index >= 0; --index) { output[index] = (unsigned char) value; value >>= 8; }
}

static void initialize_minhash_parameters(void)
{
    char seed[64];
    unsigned char digest[8];
    int index, length;
    for (index = 0; index < DEDUPE_MINHASH_PERMUTATIONS; ++index) {
        length = snprintf(seed, sizeof(seed), "minhash-perm-%d", index);
        dedupe_blake2b(digest, 8, seed, (size_t) length);
        minhash_a[index] = load_be64(digest) | UINT64_C(1);
        length = snprintf(seed, sizeof(seed), "minhash-offset-%d", index);
        dedupe_blake2b(digest, 8, seed, (size_t) length);
        minhash_b[index] = load_be64(digest);
    }
}

/*
 * Populate a set with the same UTF-8 character n-grams produced by
 * App\Service\Ngram::items().  Keeping this here avoids creating a PHP array
 * for every candidate document during the MinHash exact-Jaccard check.
 */
static zend_result populate_ngram_set(HashTable *grams, zend_string *text, zend_long size)
{
    const unsigned char *input = (const unsigned char *) ZSTR_VAL(text);
    size_t length = ZSTR_LEN(text);
    size_t *offsets;
    size_t index = 0, character_count = 0, sequence_length, gram_index;
    uint32_t codepoint;

    if (length == 0) return SUCCESS;

    offsets = safe_emalloc(length + 1, sizeof(size_t), 0);
    while (index < length) {
        offsets[character_count++] = index;
        if (input[index] <= 0x7f) {
            ++index;
            continue;
        }

        if (input[index] >= 0xc2 && input[index] <= 0xdf) {
            sequence_length = 2;
            codepoint = input[index] & 0x1f;
        } else if (input[index] >= 0xe0 && input[index] <= 0xef) {
            sequence_length = 3;
            codepoint = input[index] & 0x0f;
        } else if (input[index] >= 0xf0 && input[index] <= 0xf4) {
            sequence_length = 4;
            codepoint = input[index] & 0x07;
        } else {
            /* preg_match_all('/./us') also yields no grams for invalid UTF-8. */
            efree(offsets);
            return SUCCESS;
        }

        if (index + sequence_length > length) {
            efree(offsets);
            return SUCCESS;
        }
        for (size_t byte_index = 1; byte_index < sequence_length; ++byte_index) {
            if ((input[index + byte_index] & 0xc0) != 0x80) {
                efree(offsets);
                return SUCCESS;
            }
            codepoint = (codepoint << 6) | (input[index + byte_index] & 0x3f);
        }
        if ((sequence_length == 3 && codepoint < 0x800)
            || (sequence_length == 4 && codepoint < 0x10000)
            || (codepoint >= 0xd800 && codepoint <= 0xdfff)
            || codepoint > 0x10ffff) {
            efree(offsets);
            return SUCCESS;
        }
        index += sequence_length;
    }
    offsets[character_count] = length;

    if (character_count <= (size_t) size) {
        zend_hash_str_add_empty_element(grams, ZSTR_VAL(text), length);
    } else {
        for (gram_index = 0; gram_index <= character_count - (size_t) size; ++gram_index) {
            zend_hash_str_add_empty_element(
                grams,
                ZSTR_VAL(text) + offsets[gram_index],
                offsets[gram_index + size] - offsets[gram_index]
            );
        }
    }
    efree(offsets);
    return SUCCESS;
}

static double ngram_jaccard(HashTable *left_grams, HashTable *right_grams)
{
    HashTable *smaller, *larger;
    zend_string *key;
    zval *entry;
    uint32_t intersection = 0;
    uint32_t left_count = zend_hash_num_elements(left_grams);
    uint32_t right_count = zend_hash_num_elements(right_grams);

    if (left_count == 0 && right_count == 0) return 1.0;
    if (left_count == 0 || right_count == 0) return 0.0;

    smaller = left_count <= right_count ? left_grams : right_grams;
    larger = smaller == left_grams ? right_grams : left_grams;
    ZEND_HASH_FOREACH_STR_KEY_VAL(smaller, key, entry) {
        if (key != NULL && zend_hash_exists(larger, key)) ++intersection;
    } ZEND_HASH_FOREACH_END();

    return (double) intersection / (left_count + right_count - intersection);
}

PHP_FUNCTION(dedupe_blake2b)
{
    zend_string *input;
    zend_long length = 8;
    unsigned char digest[64];

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(input)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(length)
    ZEND_PARSE_PARAMETERS_END();

    if (length < 1 || length > 64) {
        zend_argument_value_error(2, "must be between 1 and 64");
        RETURN_THROWS();
    }

    if (dedupe_blake2b(digest, (size_t) length, ZSTR_VAL(input), ZSTR_LEN(input)) != 0) RETURN_FALSE;
    RETURN_STRINGL((const char *) digest, length);
}

PHP_FUNCTION(dedupe_minhash_signature)
{
    zval *grams;
    zval *gram;
    uint64_t signature[DEDUPE_MINHASH_PERMUTATIONS];
    unsigned char digest[8], encoded[8];
    uint64_t hash, value;
    int index;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(grams)
    ZEND_PARSE_PARAMETERS_END();

    for (index = 0; index < DEDUPE_MINHASH_PERMUTATIONS; ++index) signature[index] = UINT64_MAX;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(grams), gram) {
        ZVAL_DEREF(gram);
        if (Z_TYPE_P(gram) != IS_STRING) {
            zend_type_error("dedupe_minhash_signature(): every gram must be a string");
            RETURN_THROWS();
        }
        dedupe_blake2b(digest, 8, Z_STRVAL_P(gram), Z_STRLEN_P(gram));
        hash = load_be64(digest);
        for (index = 0; index < DEDUPE_MINHASH_PERMUTATIONS; ++index) {
            value = hash * minhash_a[index] + minhash_b[index];
            if (value < signature[index]) signature[index] = value;
        }
    } ZEND_HASH_FOREACH_END();

    array_init_size(return_value, DEDUPE_MINHASH_PERMUTATIONS);
    for (index = 0; index < DEDUPE_MINHASH_PERMUTATIONS; ++index) {
        store_be64(encoded, signature[index]);
        add_next_index_stringl(return_value, (const char *) encoded, 8);
    }
}

PHP_FUNCTION(dedupe_simhash)
{
    zval *grams;
    zval *gram;
    zend_long gram_count;
    int weights[128] = {0};
    unsigned char digest[16], output[16] = {0};
    int bit, byte_index;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(grams)
    ZEND_PARSE_PARAMETERS_END();

    gram_count = zend_hash_num_elements(Z_ARRVAL_P(grams));
    if (gram_count == 0) RETURN_STRINGL((const char *) output, 16);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(grams), gram) {
        ZVAL_DEREF(gram);
        if (Z_TYPE_P(gram) != IS_STRING) {
            zend_type_error("dedupe_simhash(): every gram must be a string");
            RETURN_THROWS();
        }
        dedupe_blake2b(digest, 16, Z_STRVAL_P(gram), Z_STRLEN_P(gram));
        for (bit = 0; bit < 128; ++bit) {
            byte_index = 15 - bit / 8;
            weights[bit] += (digest[byte_index] & (1U << (bit % 8))) ? 1 : -1;
        }
    } ZEND_HASH_FOREACH_END();

    for (bit = 0; bit < 128; ++bit) {
        if (weights[bit] >= 0) output[15 - bit / 8] |= (unsigned char) (1U << (bit % 8));
    }
    RETURN_STRINGL((const char *) output, 16);
}

PHP_FUNCTION(dedupe_uint64_decimals)
{
    zval *values;
    zval *item;
    uint64_t value;
    char decimal[21];
    int length;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(values)
    ZEND_PARSE_PARAMETERS_END();

    array_init_size(return_value, zend_hash_num_elements(Z_ARRVAL_P(values)));
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(values), item) {
        ZVAL_DEREF(item);
        if (Z_TYPE_P(item) != IS_STRING || Z_STRLEN_P(item) != 8) {
            zend_type_error("dedupe_uint64_decimals(): every value must be an eight-byte string");
            RETURN_THROWS();
        }

        value = load_be64((const unsigned char *) Z_STRVAL_P(item));
        length = snprintf(decimal, sizeof(decimal), "%llu", (unsigned long long) value);
        add_next_index_stringl(return_value, decimal, length);
    } ZEND_HASH_FOREACH_END();
}

PHP_FUNCTION(dedupe_jaccard_ngram)
{
    zend_string *left, *right;
    zend_long size = 5;
    HashTable left_grams, right_grams;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(left)
        Z_PARAM_STR(right)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(size)
    ZEND_PARSE_PARAMETERS_END();

    if (size < 1) {
        zend_argument_value_error(3, "must be greater than 0");
        RETURN_THROWS();
    }

    zend_hash_init(&left_grams, 0, NULL, NULL, 0);
    zend_hash_init(&right_grams, 0, NULL, NULL, 0);
    populate_ngram_set(&left_grams, left, size);
    populate_ngram_set(&right_grams, right, size);

    double score = ngram_jaccard(&left_grams, &right_grams);
    zend_hash_destroy(&left_grams);
    zend_hash_destroy(&right_grams);
    RETURN_DOUBLE(score);
}

PHP_FUNCTION(dedupe_jaccard_ngram_many)
{
    zend_string *left;
    zval *rights, *right;
    zend_long size = 5;
    HashTable left_grams, right_grams;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(left)
        Z_PARAM_ARRAY(rights)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(size)
    ZEND_PARSE_PARAMETERS_END();

    if (size < 1) {
        zend_argument_value_error(3, "must be greater than 0");
        RETURN_THROWS();
    }

    zend_hash_init(&left_grams, 0, NULL, NULL, 0);
    populate_ngram_set(&left_grams, left, size);
    array_init_size(return_value, zend_hash_num_elements(Z_ARRVAL_P(rights)));
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(rights), right) {
        ZVAL_DEREF(right);
        if (Z_TYPE_P(right) != IS_STRING) {
            zend_hash_destroy(&left_grams);
            zend_type_error("dedupe_jaccard_ngram_many(): every right text must be a string");
            RETURN_THROWS();
        }
        zend_hash_init(&right_grams, 0, NULL, NULL, 0);
        populate_ngram_set(&right_grams, Z_STR_P(right), size);
        add_next_index_double(return_value, ngram_jaccard(&left_grams, &right_grams));
        zend_hash_destroy(&right_grams);
    } ZEND_HASH_FOREACH_END();
    zend_hash_destroy(&left_grams);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dedupe_blake2b, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, input, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, length, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dedupe_minhash_signature, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, grams, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dedupe_simhash, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, grams, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dedupe_uint64_decimals, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, values, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dedupe_jaccard_ngram, 0, 2, IS_DOUBLE, 0)
    ZEND_ARG_TYPE_INFO(0, left, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, right, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, size, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dedupe_jaccard_ngram_many, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, left, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, rights, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, size, IS_LONG, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry dedupe_blake2b_functions[] = {
    PHP_FE(dedupe_blake2b, arginfo_dedupe_blake2b)
    PHP_FE(dedupe_minhash_signature, arginfo_dedupe_minhash_signature)
    PHP_FE(dedupe_simhash, arginfo_dedupe_simhash)
    PHP_FE(dedupe_uint64_decimals, arginfo_dedupe_uint64_decimals)
    PHP_FE(dedupe_jaccard_ngram, arginfo_dedupe_jaccard_ngram)
    PHP_FE(dedupe_jaccard_ngram_many, arginfo_dedupe_jaccard_ngram_many)
    PHP_FE_END
};

PHP_MINFO_FUNCTION(dedupe_blake2b)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "dedupe_blake2b support", "enabled");
    php_info_print_table_row(2, "version", PHP_DEDUPE_BLAKE2B_VERSION);
    php_info_print_table_end();
}

PHP_MINIT_FUNCTION(dedupe_blake2b)
{
    initialize_minhash_parameters();
    return SUCCESS;
}

zend_module_entry dedupe_blake2b_module_entry = {
    STANDARD_MODULE_HEADER,
    "dedupe_blake2b",
    dedupe_blake2b_functions,
    PHP_MINIT(dedupe_blake2b), NULL, NULL, NULL,
    PHP_MINFO(dedupe_blake2b),
    PHP_DEDUPE_BLAKE2B_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_DEDUPE_BLAKE2B
ZEND_GET_MODULE(dedupe_blake2b)
#endif
