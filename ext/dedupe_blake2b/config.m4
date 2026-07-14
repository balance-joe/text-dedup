PHP_ARG_ENABLE([dedupe_blake2b], [whether to enable dedupe_blake2b],
  [AS_HELP_STRING([--enable-dedupe_blake2b], [Enable dedupe BLAKE2b extension])], [no])

if test "$PHP_DEDUPE_BLAKE2B" != "no"; then
  PHP_NEW_EXTENSION([dedupe_blake2b], [dedupe_blake2b.c blake2b_ref.c], [$ext_shared])
fi
