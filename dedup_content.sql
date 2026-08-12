/*
 Navicat Premium Dump SQL

 Source Server         : 内容去重服务-pg
 Source Server Type    : PostgreSQL
 Source Server Version : 180004 (180004)
 Source Host           : 192.168.2.42:5432
 Source Catalog        : dedup_content
 Source Schema         : dedup_content

 Target Server Type    : PostgreSQL
 Target Server Version : 180004 (180004)
 File Encoding         : 65001

 Date: 12/08/2026 11:50:31
*/


-- ----------------------------
-- Sequence structure for document_fingerprint_doc_pk_seq
-- ----------------------------
DROP SEQUENCE IF EXISTS "dedup_content"."document_fingerprint_doc_pk_seq";
CREATE SEQUENCE "dedup_content"."document_fingerprint_doc_pk_seq" 
INCREMENT 1
MINVALUE  1
MAXVALUE 9223372036854775807
START 1
CACHE 1;

-- ----------------------------
-- Table structure for dedupe_meta
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."dedupe_meta";
CREATE TABLE "dedup_content"."dedupe_meta" (
  "meta_key" text COLLATE "pg_catalog"."default" NOT NULL,
  "meta_value" text COLLATE "pg_catalog"."default" NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
;
COMMENT ON COLUMN "dedup_content"."dedupe_meta"."meta_key" IS '元数据键名。';
COMMENT ON COLUMN "dedup_content"."dedupe_meta"."meta_value" IS '元数据值。';
COMMENT ON COLUMN "dedup_content"."dedupe_meta"."created_at" IS '元数据首次写入时间。';
COMMENT ON COLUMN "dedup_content"."dedupe_meta"."updated_at" IS '元数据最后更新时间；算法参数不得通过普通初始化脚本更新。';
COMMENT ON TABLE "dedup_content"."dedupe_meta" IS '去重算法与索引格式元数据；索引生成后参数不可原地修改。';

-- ----------------------------
-- Table structure for document_fingerprint
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."document_fingerprint";
CREATE TABLE "dedup_content"."document_fingerprint" (
  "doc_pk" int8 NOT NULL GENERATED ALWAYS AS IDENTITY (
INCREMENT 1
MINVALUE  1
MAXVALUE 9223372036854775807
START 1
CACHE 1
),
  "source_from" varchar(128) COLLATE "pg_catalog"."C" NOT NULL DEFAULT ''::character varying,
  "external_id" varchar(128) COLLATE "pg_catalog"."C" NOT NULL,
  "content_hash" bytea NOT NULL,
  "title_hash" bytea,
  "raw_hash" bytea NOT NULL,
  "simhash_hi" int8 NOT NULL,
  "simhash_lo" int8 NOT NULL,
  "title_simhash_hi" int8,
  "title_simhash_lo" int8,
  "low_information" bool NOT NULL DEFAULT false,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
;
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."doc_pk" IS '服务内部文档主键，由数据库自动生成。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."source_from" IS '上游来源标识；空字符串表示未区分来源。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."external_id" IS '来源系统中的文档唯一标识。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."content_hash" IS '归一化正文的 128 位精确去重哈希，固定 16 字节。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."title_hash" IS '归一化标题的 128 位哈希，标题缺失时为空，固定 16 字节。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."raw_hash" IS '原始文本的 128 位哈希，固定 16 字节。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."simhash_hi" IS '正文 128 位 SimHash 的高 64 位，以有符号 BIGINT 补码位模式保存。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."simhash_lo" IS '正文 128 位 SimHash 的低 64 位，以有符号 BIGINT 补码位模式保存。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."title_simhash_hi" IS '标题 128 位 SimHash 的高 64 位；无有效标题时为空。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."title_simhash_lo" IS '标题 128 位 SimHash 的低 64 位；无有效标题时为空。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."low_information" IS '是否为低信息量文本；用于下游去重策略降级或过滤。';
COMMENT ON COLUMN "dedup_content"."document_fingerprint"."created_at" IS '文档指纹创建时间，用于保留策略与按时间清理。';
COMMENT ON TABLE "dedup_content"."document_fingerprint" IS '文档指纹热表，存放唯一标识、哈希和 SimHash，不存放大文本。';

-- ----------------------------
-- Table structure for document_text
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."document_text";
CREATE TABLE "dedup_content"."document_text" (
  "doc_pk" int8 NOT NULL,
  "normalized_title" text COLLATE "pg_catalog"."default",
  "normalized_content" text COLLATE "pg_catalog"."default",
  "primary_text" text COLLATE "pg_catalog"."default" NOT NULL
)
;
COMMENT ON COLUMN "dedup_content"."document_text"."doc_pk" IS '关联 document_fingerprint 的内部文档主键。';
COMMENT ON COLUMN "dedup_content"."document_text"."normalized_title" IS '用于标题去重的归一化标题文本。';
COMMENT ON COLUMN "dedup_content"."document_text"."normalized_content" IS '用于正文去重的归一化正文文本。';
COMMENT ON COLUMN "dedup_content"."document_text"."primary_text" IS '参与 MinHash/Jaccard 精确校验的主文本。';
COMMENT ON TABLE "dedup_content"."document_text" IS '文档归一化文本表，与指纹热表垂直拆分，并随主文档级联删除。';

-- ----------------------------
-- Table structure for minhash_band_p0
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p0";
CREATE TABLE "dedup_content"."minhash_band_p0" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p0"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 0。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p0"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p0"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p0" IS 'minhash_band 的 band_index=0 子分区';

-- ----------------------------
-- Table structure for minhash_band_p1
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p1";
CREATE TABLE "dedup_content"."minhash_band_p1" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p1"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 1。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p1"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p1"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p1" IS 'minhash_band 的 band_index=1 子分区';

-- ----------------------------
-- Table structure for minhash_band_p10
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p10";
CREATE TABLE "dedup_content"."minhash_band_p10" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p10"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 10。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p10"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p10"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p10" IS 'minhash_band 的 band_index=10 子分区';

-- ----------------------------
-- Table structure for minhash_band_p11
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p11";
CREATE TABLE "dedup_content"."minhash_band_p11" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p11"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 11。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p11"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p11"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p11" IS 'minhash_band 的 band_index=11 子分区';

-- ----------------------------
-- Table structure for minhash_band_p12
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p12";
CREATE TABLE "dedup_content"."minhash_band_p12" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p12"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 12。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p12"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p12"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p12" IS 'minhash_band 的 band_index=12 子分区';

-- ----------------------------
-- Table structure for minhash_band_p13
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p13";
CREATE TABLE "dedup_content"."minhash_band_p13" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p13"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 13。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p13"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p13"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p13" IS 'minhash_band 的 band_index=13 子分区';

-- ----------------------------
-- Table structure for minhash_band_p14
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p14";
CREATE TABLE "dedup_content"."minhash_band_p14" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p14"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 14。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p14"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p14"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p14" IS 'minhash_band 的 band_index=14 子分区';

-- ----------------------------
-- Table structure for minhash_band_p15
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p15";
CREATE TABLE "dedup_content"."minhash_band_p15" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p15"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 15。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p15"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p15"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p15" IS 'minhash_band 的 band_index=15 子分区';

-- ----------------------------
-- Table structure for minhash_band_p16
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p16";
CREATE TABLE "dedup_content"."minhash_band_p16" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p16"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 16。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p16"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p16"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p16" IS 'minhash_band 的 band_index=16 子分区';

-- ----------------------------
-- Table structure for minhash_band_p17
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p17";
CREATE TABLE "dedup_content"."minhash_band_p17" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p17"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 17。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p17"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p17"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p17" IS 'minhash_band 的 band_index=17 子分区';

-- ----------------------------
-- Table structure for minhash_band_p18
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p18";
CREATE TABLE "dedup_content"."minhash_band_p18" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p18"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 18。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p18"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p18"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p18" IS 'minhash_band 的 band_index=18 子分区';

-- ----------------------------
-- Table structure for minhash_band_p19
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p19";
CREATE TABLE "dedup_content"."minhash_band_p19" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p19"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 19。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p19"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p19"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p19" IS 'minhash_band 的 band_index=19 子分区';

-- ----------------------------
-- Table structure for minhash_band_p2
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p2";
CREATE TABLE "dedup_content"."minhash_band_p2" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p2"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 2。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p2"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p2"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p2" IS 'minhash_band 的 band_index=2 子分区';

-- ----------------------------
-- Table structure for minhash_band_p20
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p20";
CREATE TABLE "dedup_content"."minhash_band_p20" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p20"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 20。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p20"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p20"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p20" IS 'minhash_band 的 band_index=20 子分区';

-- ----------------------------
-- Table structure for minhash_band_p21
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p21";
CREATE TABLE "dedup_content"."minhash_band_p21" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p21"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 21。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p21"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p21"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p21" IS 'minhash_band 的 band_index=21 子分区';

-- ----------------------------
-- Table structure for minhash_band_p22
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p22";
CREATE TABLE "dedup_content"."minhash_band_p22" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p22"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 22。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p22"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p22"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p22" IS 'minhash_band 的 band_index=22 子分区';

-- ----------------------------
-- Table structure for minhash_band_p23
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p23";
CREATE TABLE "dedup_content"."minhash_band_p23" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p23"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 23。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p23"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p23"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p23" IS 'minhash_band 的 band_index=23 子分区';

-- ----------------------------
-- Table structure for minhash_band_p24
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p24";
CREATE TABLE "dedup_content"."minhash_band_p24" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p24"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 24。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p24"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p24"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p24" IS 'minhash_band 的 band_index=24 子分区';

-- ----------------------------
-- Table structure for minhash_band_p25
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p25";
CREATE TABLE "dedup_content"."minhash_band_p25" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p25"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 25。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p25"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p25"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p25" IS 'minhash_band 的 band_index=25 子分区';

-- ----------------------------
-- Table structure for minhash_band_p26
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p26";
CREATE TABLE "dedup_content"."minhash_band_p26" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p26"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 26。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p26"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p26"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p26" IS 'minhash_band 的 band_index=26 子分区';

-- ----------------------------
-- Table structure for minhash_band_p27
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p27";
CREATE TABLE "dedup_content"."minhash_band_p27" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p27"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 27。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p27"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p27"."doc_pk" IS '命中该 band 的内部文档主键。';
COMMENT ON TABLE "dedup_content"."minhash_band_p27" IS 'minhash_band 的 band_index=27 子分区';

-- ----------------------------
-- Table structure for minhash_band_p28
-- ----------------------------
DROP TABLE IF EXISTS "dedup_content"."minhash_band_p28";
CREATE TABLE "dedup_content"."minhash_band_p28" (
  "band_index" int2 NOT NULL,
  "band_value" int8 NOT NULL,
  "doc_pk" int8 NOT NULL,
  "created_at" timestamptz(6) NOT NULL DEFAULT CURRENT_TIMESTAMP
)
WITH (fillfactor=100)
;
COMMENT ON COLUMN "dedup_content"."minhash_band_p28"."band_index" IS '所属 band 序号；由 LIST 分区边界固定为 28。';
COMMENT ON COLUMN "dedup_content"."minhash_band_p28"."band_value" IS '该 band 的哈希值，用于候选文档倒排查询。';
COMMENT ON COLUMN 