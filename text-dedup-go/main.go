package main

import (
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"math"
	"os"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/blake2b"
)

const permutations = 128

func processMemory() map[string]any {
	var stats runtime.MemStats
	runtime.ReadMemStats(&stats)
	result := map[string]any{
		"process_current_rss_mb": nil,
		"process_peak_rss_mb":    nil,
		"process_metric":         "unavailable on this operating system",
		"go_heap_allocated_mb":   math.Round(float64(stats.Alloc)/1024/1024*1000) / 1000,
		"go_runtime_sys_mb":       math.Round(float64(stats.Sys)/1024/1024*1000) / 1000,
		"go_runtime_metric":       "Go runtime.MemStats Alloc and Sys",
	}
	status, err := os.ReadFile("/proc/self/status")
	if err != nil {
		return result
	}
	for _, line := range strings.Split(string(status), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		value, parseErr := strconv.ParseFloat(fields[1], 64)
		if parseErr != nil {
			continue
		}
		switch fields[0] {
		case "VmRSS:":
			result["process_current_rss_mb"] = math.Round(value/1024*1000) / 1000
		case "VmHWM:":
			result["process_peak_rss_mb"] = math.Round(value/1024*1000) / 1000
		}
	}
	result["process_metric"] = "Linux /proc/self/status VmRSS and VmHWM"
	return result
}

type Baseline struct {
	Records []Record `json:"records"`
}
type Record struct {
	DocPK    int      `json:"doc_pk"`
	Input    Input    `json:"canonical_input"`
	Expected Expected `json:"expected_from_canonical_input"`
}
type Input struct {
	Title   string `json:"title"`
	Content string `json:"content"`
}
type Expected struct {
	RawHash           string  `json:"raw_hash_hex"`
	ContentHash       string  `json:"content_hash_hex"`
	TitleHash         *string `json:"title_hash_hex"`
	NormalizedTitle   string  `json:"normalized_title"`
	NormalizedContent string  `json:"normalized_content"`
	PrimaryText       string  `json:"primary_text"`
	Content           Scope   `json:"content_scope"`
	Title             *Scope  `json:"title_scope"`
}
type Scope struct {
	Text           string    `json:"text"`
	TextLen        int       `json:"text_len"`
	LowInformation bool      `json:"low_information"`
	ExactHash      string    `json:"exact_hash"`
	SimHash        string    `json:"simhash_hex"`
	SimHashHi      int64     `json:"simhash_hi_pg_bigint"`
	SimHashLo      int64     `json:"simhash_lo_pg_bigint"`
	SimHashBands   []SimBand `json:"simhash_bands"`
	Signature      []uint64  `json:"minhash_signature_uint64"`
	MinHashBands   []MinBand `json:"minhash_bands_uint64"`
}
type result struct {
	Doc   int    `json:"doc_pk"`
	Error string `json:"error"`
}

var permA, permB [permutations]uint64

func digest(input string, length int) []byte {
	h, _ := blake2b.New(length, nil)
	_, _ = h.Write([]byte(input))
	return h.Sum(nil)
}
func stable64(input string) uint64 { return binary.BigEndian.Uint64(digest(input, 8)) }

func init() {
	for i := 0; i < permutations; i++ {
		permA[i] = stable64(fmt.Sprintf("minhash-perm-%d", i)) | 1
		permB[i] = stable64(fmt.Sprintf("minhash-offset-%d", i))
	}
}

func gramList(text string) []string {
	r := []rune(text)
	if len(r) == 0 {
		return nil
	}
	if len(r) <= 5 {
		return []string{text}
	}
	out := make([]string, 0, len(r)-4)
	for i := 0; i <= len(r)-5; i++ {
		out = append(out, string(r[i:i+5]))
	}
	return out
}
func gramSet(text string) map[string]struct{} {
	out := make(map[string]struct{})
	for _, gram := range gramList(text) {
		out[gram] = struct{}{}
	}
	return out
}

func simhash(text string) string {
	weights := [128]int{}
	for _, gram := range gramList(text) {
		d := digest(gram, 16)
		for bit := 0; bit < 128; bit++ {
			if d[15-bit/8]&(1<<uint(bit%8)) != 0 {
				weights[bit]++
			} else {
				weights[bit]--
			}
		}
	}
	out := [16]byte{}
	for bit, w := range weights {
		if w >= 0 {
			out[15-bit/8] |= 1 << uint(bit%8)
		}
	}
	return hex.EncodeToString(out[:])
}

func minhash(text string) []uint64 {
	sig := make([]uint64, permutations)
	for i := range sig {
		sig[i] = math.MaxUint64
	}
	for gram := range gramSet(text) {
		h := stable64(gram)
		for i := 0; i < permutations; i++ {
			v := h*permA[i] + permB[i]
			if v < sig[i] {
				sig[i] = v
			}
		}
	}
	return sig
}

func main() {
	input := flag.String("input", "", "Python baseline JSON path")
	workers := flag.Int("workers", runtime.NumCPU(), "parallel workers")
	flag.Parse()
	if *input == "" {
		fmt.Fprintln(os.Stderr, "usage: text-dedup-go -input <php-compat-baseline.json> [-workers N]")
		os.Exit(2)
	}
	file, err := os.ReadFile(*input)
	if err != nil {
		panic(err)
	}
	var base Baseline
	if err = json.Unmarshal(file, &base); err != nil {
		panic(err)
	}
	if *workers < 1 {
		*workers = 1
	}
	started := time.Now()
	jobs := make(chan Record)
	results := make(chan result, len(base.Records))
	var wg sync.WaitGroup
	for n := 0; n < *workers; n++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for r := range jobs {
				results <- verify(r)
			}
		}()
	}
	go func() {
		for _, r := range base.Records {
			jobs <- r
		}
		close(jobs)
		wg.Wait()
		close(results)
	}()
	failures := []result{}
	for r := range results {
		if r.Error != "" {
			failures = append(failures, r)
		}
	}
	failureCount := len(failures)
	elapsed := time.Since(started).Seconds()
	scopes := 0
	for _, record := range base.Records {
		scopes++
		if record.Expected.Title != nil {
			scopes++
		}
	}
	out := map[string]any{"language": "go", "contract": "full-fingerprint", "records": len(base.Records), "scopes": scopes, "workers": *workers, "elapsed_seconds": elapsed, "records_per_second": float64(len(base.Records)) / elapsed, "memory": processMemory(), "failure_count": failureCount, "failures": []result{}, "status": "passed"}
	if failureCount > 0 {
		out["status"] = "failed"
		if len(failures) > 5 {
			failures = failures[:5]
		}
		out["failures"] = failures
	}
	b, _ := json.MarshalIndent(out, "", "  ")
	fmt.Println(string(b))
	if failureCount > 0 {
		os.Exit(1)
	}
}
