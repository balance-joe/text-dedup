#ifndef DEDUPE_BLAKE2B_REF_H
#define DEDUPE_BLAKE2B_REF_H
#include <stddef.h>
int dedupe_blake2b(unsigned char *out, size_t outlen, const void *in, size_t inlen);
#endif
