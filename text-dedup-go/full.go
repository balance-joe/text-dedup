package main

import (
	"crypto/md5"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
	"unicode"
)

type SimBand struct {
	Index int
	Value string
}
type MinBand struct {
	Index int
	Value uint64
}

func (b *SimBand) UnmarshalJSON(v []byte) error {
	var x []json.RawMessage
	if err := json.Unmarshal(v, &x); err != nil {
		return err
	}
	return first(json.Unmarshal(x[0], &b.Index), json.Unmarshal(x[1], &b.Value))
}
func (b *MinBand) UnmarshalJSON(v []byte) error {
	var x []json.RawMessage
	if err := json.Unmarshal(v, &x); err != nil {
		return err
	}
	return first(json.Unmarshal(x[0], &b.Index), json.Unmarshal(x[1], &b.Value))
}
func first(a, b error) error {
	if a != nil {
		return a
	}
	return b
}

var tokenRE = regexp.MustCompile(`\[[^\[\]]{1,20}\]`)
var digitRE = regexp.MustCompile(`[\p{Nd}]+`)

func emoji(r rune) bool {
	ranges := [][2]rune{{0x1F600, 0x1F64F}, {0x1F300, 0x1F5FF}, {0x1F680, 0x1F6FF}, {0x1F1E0, 0x1F1FF}, {0x1F900, 0x1F9FF}, {0x1FA00, 0x1FAFF}, {0x2600, 0x27BF}, {0xFE00, 0xFE0F}}
	for _, x := range ranges {
		if r >= x[0] && r <= x[1] {
			return true
		}
	}
	return r == 0x200D || r == 0x20E3
}
func compact(s string) string {
	return strings.Map(func(r rune) rune {
		if unicode.IsSpace(r) {
			return -1
		}
		return r
	}, s)
}
func normalize(s string) string {
	s = strings.ReplaceAll(strings.ReplaceAll(s, "\u200b", ""), "\ufeff", "")
	original := strings.ToLower(compact(s))
	s = strings.Map(func(r rune) rune {
		if emoji(r) {
			return -1
		}
		return r
	}, s)
	s = tokenRE.ReplaceAllString(s, "")
	s = digitRE.ReplaceAllString(s, "0")
	s = strings.ReplaceAll(s, "0.0", "0")
	s = strings.Map(func(r rune) rune {
		if (r >= 0x4e00 && r <= 0x9fff) || (r >= 0x3000 && r <= 0x303f) || (r >= 0xff00 && r <= 0xffef) {
			return r
		}
		return -1
	}, s)
	s = strings.ToLower(compact(s))
	if s == "" {
		return original
	}
	return s
}
func md5hex(s string) string { x := md5.Sum([]byte(s)); return hex.EncodeToString(x[:]) }
func lowInfo(s string) bool {
	r := []rune(s)
	if len(r) == 0 {
		return true
	}
	tokens := tokenRE.FindAllString(s, -1)
	tc := 0
	for _, t := range tokens {
		tc += len([]rune(t))
	}
	valid := 0
	for _, c := range r {
		if unicode.IsLetter(c) || unicode.IsDigit(c) || (c >= 0x4e00 && c <= 0x9fff) {
			valid++
		}
	}
	return len(r) < 30 || (len(tokens) > 0 && float64(tc)/float64(len(r)) >= .65) || valid < 10
}
func equalScope(text string, w Scope) string {
	sh := simhash(text)
	raw, _ := hex.DecodeString(sh)
	hi := int64(binary.BigEndian.Uint64(raw[:8]))
	lo := int64(binary.BigEndian.Uint64(raw[8:]))
	if sh != w.SimHash || hi != w.SimHashHi || lo != w.SimHashLo {
		return "simhash"
	}
	for i := 0; i < 8; i++ {
		v := sh[28-i*4 : 32-i*4]
		if w.SimHashBands[i].Index != i || w.SimHashBands[i].Value != v {
			return "simhash_bands"
		}
	}
	sig := minhash(text)
	for i, v := range sig {
		if v != w.Signature[i] {
			return fmt.Sprintf("minhash[%d]", i)
		}
	}
	for i := 0; i < 32; i++ {
		if w.MinHashBands[i].Index != i || w.MinHashBands[i].Value != sig[i] {
			return "minhash_bands"
		}
	}
	if text != w.Text || len([]rune(text)) != w.TextLen || lowInfo(text) != w.LowInformation {
		return "text_metadata"
	}
	return ""
}
func verify(r Record) result {
	title := normalize(r.Input.Title)
	content := normalize(r.Input.Content)
	raw := md5hex("title\x1f" + r.Input.Title + "\x1econtent\x1f" + r.Input.Content)
	ch := ""
	if content != "" {
		ch = md5hex(content)
	}
	var th *string
	if title != "" {
		x := md5hex(title)
		th = &x
	}
	if title != r.Expected.NormalizedTitle || content != r.Expected.NormalizedContent || raw != r.Expected.RawHash || ch != r.Expected.ContentHash || !samePtr(th, r.Expected.TitleHash) {
		return result{r.DocPK, "hash_or_normalization"}
	}
	primary := content
	if primary == "" {
		primary = title
	}
	if primary != r.Expected.PrimaryText {
		return result{r.DocPK, "primary_text"}
	}
	contentExact := ch
	if contentExact == "" && th != nil {
		contentExact = *th
	}
	if contentExact != r.Expected.Content.ExactHash {
		return result{r.DocPK, "content:exact_hash"}
	}
	if e := equalScope(primary, r.Expected.Content); e != "" {
		return result{r.DocPK, e}
	}
	if r.Expected.Title != nil {
		if th == nil || *th != r.Expected.Title.ExactHash {
			return result{r.DocPK, "title:exact_hash"}
		}
		if e := equalScope(title, *r.Expected.Title); e != "" {
			return result{r.DocPK, "title:" + e}
		}
	}
	return result{Doc: r.DocPK}
}
func samePtr(a, b *string) bool {
	if a == nil || b == nil {
		return a == nil && b == nil
	}
	return *a == *b
}
