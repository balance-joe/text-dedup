package main

import (
	"crypto/md5"
	"encoding/binary"
	"encoding/hex"
	"fmt"
	"math"
	"regexp"
	"strings"
	"unicode"

	"golang.org/x/crypto/blake2b"
	"golang.org/x/text/unicode/norm"
)

const fingerprintPermutations = 128

type fingerprint struct {
	NormalizedTitle   string
	NormalizedContent string
	RawHash           string
	ContentHash       *string
	TitleHash         *string
	Text              string
	ExactHash         string
	Simhash           [16]byte
	SimhashBands      [8]uint16
	MinhashBands      [32]uint64
}

var fpTokenRE = regexp.MustCompile(`\[[^\[\]]{1,20}\]`)
var fpDigitRE = regexp.MustCompile(`[\p{Nd}]+`)
var fpPermA, fpPermB [fingerprintPermutations]uint64

func init() {
	for i := 0; i < fingerprintPermutations; i++ {
		fpPermA[i] = fpStable64(fmt.Sprintf("minhash-perm-%d", i)) | 1
		fpPermB[i] = fpStable64(fmt.Sprintf("minhash-offset-%d", i))
	}
}

func makeFingerprint(title, content string, titleScope bool) fingerprint {
	normalizedTitle, normalizedContent := fpNormalize(title), fpNormalize(content)
	text := normalizedTitle
	if !titleScope && normalizedContent != "" {
		text = normalizedContent
	}
	raw := fpMD5("title\x1f" + title + "\x1econtent\x1f" + content)
	var contentHash, titleHash *string
	if normalizedContent != "" {
		value := fpMD5(normalizedContent)
		contentHash = &value
	}
	if normalizedTitle != "" {
		value := fpMD5(normalizedTitle)
		titleHash = &value
	}
	exact := ""
	if titleScope {
		if titleHash != nil {
			exact = *titleHash
		}
	} else if contentHash != nil {
		exact = *contentHash
	} else if titleHash != nil {
		exact = *titleHash
	}
	sim := fpSimhash(text)
	return fingerprint{NormalizedTitle: normalizedTitle, NormalizedContent: normalizedContent, RawHash: raw, ContentHash: contentHash, TitleHash: titleHash, Text: text, ExactHash: exact, Simhash: sim, SimhashBands: fpSimhashBands(sim), MinhashBands: fpMinhash(text)}
}

func fpMD5(value string) string { sum := md5.Sum([]byte(value)); return hex.EncodeToString(sum[:]) }
func fpDigest(input string, length int) []byte {
	h, _ := blake2b.New(length, nil)
	_, _ = h.Write([]byte(input))
	return h.Sum(nil)
}
func fpStable64(input string) uint64 { return binary.BigEndian.Uint64(fpDigest(input, 8)) }
func fpGrams(text string) []string {
	runes := []rune(text)
	if len(runes) == 0 {
		return nil
	}
	if len(runes) <= 5 {
		return []string{text}
	}
	result := make([]string, 0, len(runes)-4)
	for i := 0; i <= len(runes)-5; i++ {
		result = append(result, string(runes[i:i+5]))
	}
	return result
}
func fpSimhash(text string) [16]byte {
	weights := [128]int{}
	for _, gram := range fpGrams(text) {
		digest := fpDigest(gram, 16)
		for bit := 0; bit < 128; bit++ {
			if digest[15-bit/8]&(1<<uint(bit%8)) != 0 {
				weights[bit]++
			} else {
				weights[bit]--
			}
		}
	}
	var out [16]byte
	for bit, weight := range weights {
		if weight >= 0 {
			out[15-bit/8] |= 1 << uint(bit%8)
		}
	}
	return out
}
func fpSimhashBands(value [16]byte) [8]uint16 {
	var bands [8]uint16
	for index := range bands {
		offset := 14 - index*2
		bands[index] = binary.BigEndian.Uint16(value[offset : offset+2])
	}
	return bands
}
func fpMinhash(text string) [32]uint64 {
	signature := [fingerprintPermutations]uint64{}
	for i := range signature {
		signature[i] = math.MaxUint64
	}
	grams := map[string]struct{}{}
	for _, gram := range fpGrams(text) {
		grams[gram] = struct{}{}
	}
	for gram := range grams {
		hash := fpStable64(gram)
		for i := range signature {
			value := hash*fpPermA[i] + fpPermB[i]
			if value < signature[i] {
				signature[i] = value
			}
		}
	}
	var result [32]uint64
	copy(result[:], signature[:32])
	return result
}
func fpEmoji(r rune) bool {
	for _, value := range [][2]rune{{0x1F600, 0x1F64F}, {0x1F300, 0x1F5FF}, {0x1F680, 0x1F6FF}, {0x1F1E0, 0x1F1FF}, {0x1F900, 0x1F9FF}, {0x1FA00, 0x1FAFF}, {0x2600, 0x27BF}, {0xFE00, 0xFE0F}} {
		if r >= value[0] && r <= value[1] {
			return true
		}
	}
	return r == 0x200D || r == 0x20E3
}
func fpCompact(value string) string {
	return strings.Map(func(r rune) rune {
		if unicode.IsSpace(r) {
			return -1
		}
		return r
	}, value)
}
func fpNormalize(value string) string {
	value = norm.NFKC.String(value)
	value = strings.ReplaceAll(strings.ReplaceAll(value, "\u200b", ""), "\ufeff", "")
	original := strings.ToLower(fpCompact(value))
	value = strings.Map(func(r rune) rune {
		if fpEmoji(r) {
			return -1
		}
		return r
	}, value)
	value = fpTokenRE.ReplaceAllString(value, "")
	value = fpDigitRE.ReplaceAllString(value, "0")
	value = strings.ReplaceAll(value, "0.0", "0")
	value = strings.Map(func(r rune) rune {
		if (r >= 0x4e00 && r <= 0x9fff) || (r >= 0x3000 && r <= 0x303f) || (r >= 0xff00 && r <= 0xffef) {
			return r
		}
		return -1
	}, value)
	value = strings.ToLower(fpCompact(value))
	if value == "" {
		return original
	}
	return value
}
func fpJaccard(left, right string) float64 {
	a, b := map[string]struct{}{}, map[string]struct{}{}
	for _, gram := range fpGrams(left) {
		a[gram] = struct{}{}
	}
	for _, gram := range fpGrams(right) {
		b[gram] = struct{}{}
	}
	if len(a) == 0 && len(b) == 0 {
		return 1
	}
	intersection := 0
	for gram := range a {
		if _, ok := b[gram]; ok {
			intersection++
		}
	}
	return float64(intersection) / float64(len(a)+len(b)-intersection)
}
