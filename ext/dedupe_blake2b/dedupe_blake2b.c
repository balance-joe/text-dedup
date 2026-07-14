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

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dedupe_blake2b, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, input, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, length, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_dedupe_minhash_signature, 0, 1, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, grams, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry dedupe_blake2b_functions[] = {
    PHP_FE(dedupe_blake2b, arginfo_dedupe_blake2b)
    PHP_FE(dedupe_minhash_signature, arginfo_dedupe_minhash_signature)
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
