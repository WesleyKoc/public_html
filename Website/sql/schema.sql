-- =============================================================================
-- sql/schema.sql — PhyloSeq Database Schema
-- =============================================================================
-- Creates all tables, indexes, foreign keys, and views for the PhyloSeq
-- protein sequence conservation and analysis portal.
--
-- Usage:
--   mysql -u phyloseq_user -p phyloseq < sql/schema.sql
--
-- Safe to re-run: all objects use DROP IF EXISTS / CREATE IF NOT EXISTS guards.
-- Re-running will DESTROY all existing data — run on a clean install only.
--
-- Encoding:  utf8mb4 (full Unicode including emoji in descriptions)
-- Engine:    InnoDB  (referential integrity, transactions, row-level locking)
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Database-level settings
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET character_set_client = utf8mb4;

-- Temporarily disable FK checks so tables can be dropped in any order
SET FOREIGN_KEY_CHECKS = 0;


-- ---------------------------------------------------------------------------
-- 1. DROP existing objects (tables in reverse dependency order, then views)
-- ---------------------------------------------------------------------------

DROP TABLE IF EXISTS `external_links`;
DROP TABLE IF EXISTS `extra_analyses`;
DROP TABLE IF EXISTS `blast_results`;
DROP TABLE IF EXISTS `motif_hits`;
DROP TABLE IF EXISTS `conservation_scores`;
DROP TABLE IF EXISTS `alignments`;
DROP TABLE IF EXISTS `sequences`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `users`;

DROP VIEW  IF EXISTS `v_conservation_overview`;
DROP VIEW  IF EXISTS `v_motif_overview`;
DROP VIEW  IF EXISTS `v_job_summary`;


-- ---------------------------------------------------------------------------
-- 2. TABLE: users
--    One row per browser session. No login required — identified by a
--    randomly generated cookie token set on the user's first page load.
-- ---------------------------------------------------------------------------

CREATE TABLE `users` (
    `user_id`       INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Opaque random token stored in the browser cookie (phyloseq_session).
    -- VARCHAR(64) accommodates a 32-byte hex string or UUID.
    `session_token` VARCHAR(64)     NOT NULL,

    -- Optional human-readable label the user can set from the My Sessions page.
    `label`         VARCHAR(100)    DEFAULT NULL,

    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`user_id`),

    -- Each session token must be unique across all users.
    UNIQUE KEY `uq_session_token` (`session_token`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Browser sessions identified by cookie token; no login required.';


-- ---------------------------------------------------------------------------
-- 3. TABLE: jobs
--    One row per analysis run. Central pivot table — all result data
--    references back to a job.
-- ---------------------------------------------------------------------------

CREATE TABLE `jobs` (
    `job_id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,

    -- Nullable: jobs can be created before the session cookie is confirmed.
    `session_token`     VARCHAR(64)     DEFAULT NULL,

    -- User-defined label for the My Sessions page (editable post-run).
    `label`             VARCHAR(100)    DEFAULT NULL,

    -- ── Query parameters ──────────────────────────────────────────────────
    `protein_family`    VARCHAR(255)    NOT NULL DEFAULT '',
    `taxonomic_group`   VARCHAR(255)    NOT NULL DEFAULT '',

    -- The exact query string sent to NCBI Entrez (constructed by PHP).
    `ncbi_query_string` TEXT            DEFAULT NULL,

    -- ── Counts & status ───────────────────────────────────────────────────
    -- Denormalised sequence count for quick display (mirrors COUNT of sequences rows).
    `num_sequences`     INT UNSIGNED    DEFAULT 0,

    -- Pipeline execution status.
    `status`            ENUM(
                            'queued',
                            'running',
                            'done',
                            'failed',
                            'deleted'
                        ) NOT NULL DEFAULT 'queued',

    -- Flag: 1 = pre-seeded example dataset (G6Pase/Aves), 0 = user job.
    -- Example jobs are excluded from My Sessions listings.
    `is_example`        TINYINT(1)      NOT NULL DEFAULT 0,

    -- ── File system ───────────────────────────────────────────────────────
    -- Absolute path to the per-job results directory on the server.
    -- e.g. /var/www/html/phyloseq/results/42
    `session_dir`       VARCHAR(512)    DEFAULT NULL,

    -- ── Timestamps ────────────────────────────────────────────────────────
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`      TIMESTAMP       DEFAULT NULL,

    PRIMARY KEY (`job_id`),

    -- Fast lookup of all jobs for a given session token (My Sessions page).
    KEY `idx_jobs_session_token` (`session_token`),

    -- Fast filtering of active (non-deleted, non-example) jobs.
    KEY `idx_jobs_status_example` (`status`, `is_example`),

    -- Foreign key to users (nullable: allow jobs without a confirmed session).
    CONSTRAINT `fk_jobs_users`
        FOREIGN KEY (`session_token`)
        REFERENCES `users` (`session_token`)
        ON UPDATE CASCADE
        ON DELETE SET NULL

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per analysis run. Central pivot for all result data.';


-- ---------------------------------------------------------------------------
-- 4. TABLE: sequences
--    One row per retrieved protein sequence. Each sequence belongs to one job.
--    Sequences can be soft-excluded by the user before alignment.
-- ---------------------------------------------------------------------------

CREATE TABLE `sequences` (
    `seq_id`        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `job_id`        INT UNSIGNED    NOT NULL,

    -- ── NCBI identifiers ──────────────────────────────────────────────────
    `accession`     VARCHAR(50)     NOT NULL DEFAULT '',
    `taxon_id`      INT UNSIGNED    DEFAULT NULL,   -- NCBI taxonomy ID
    `description`   TEXT            DEFAULT NULL,   -- NCBI sequence title

    -- ── Taxonomy ──────────────────────────────────────────────────────────
    `organism`      VARCHAR(255)    NOT NULL DEFAULT '',

    -- Order-level taxonomic classification (e.g. Passeriformes, Falconiformes).
    -- Extracted from the GenBank lineage field by pipeline.py.
    `order_name`    VARCHAR(100)    DEFAULT NULL,

    -- ── Sequence data ─────────────────────────────────────────────────────
    -- Full amino acid sequence in single-letter code. MEDIUMTEXT allows up
    -- to 16 MB, sufficient for the largest known proteins.
    `sequence`      MEDIUMTEXT      NOT NULL,
    `length`        INT UNSIGNED    NOT NULL DEFAULT 0,

    -- ── User filtering ────────────────────────────────────────────────────
    -- Set to 1 when the user unchecks a sequence on the Fetch page.
    -- Excluded sequences are stored but skipped in all downstream analyses.
    `excluded`      TINYINT(1)      NOT NULL DEFAULT 0,

    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`seq_id`),

    -- All downstream tables join via seq_id; job_id queries are very common.
    KEY `idx_sequences_job_id`         (`job_id`),
    KEY `idx_sequences_job_excluded`   (`job_id`, `excluded`),
    KEY `idx_sequences_accession`      (`accession`),

    CONSTRAINT `fk_sequences_jobs`
        FOREIGN KEY (`job_id`)
        REFERENCES `jobs` (`job_id`)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per retrieved protein sequence, belonging to one job.';


-- ---------------------------------------------------------------------------
-- 5. TABLE: alignments
--    Alignment metadata for a job. Stores tool used, output file paths,
--    and aggregate statistics. One job has at most one alignment record
--    at any time (re-running replaces it).
-- ---------------------------------------------------------------------------

CREATE TABLE `alignments` (
    `alignment_id`      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `job_id`            INT UNSIGNED    NOT NULL,

    -- Tool used to generate the alignment.
    `tool_used`         VARCHAR(50)     NOT NULL DEFAULT 'clustalo',

    -- Counts
    `num_sequences`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `alignment_length`  INT UNSIGNED    NOT NULL DEFAULT 0, -- columns in MSA

    -- Mean pairwise % identity across all sequence pairs in the alignment.
    `avg_identity`      FLOAT           DEFAULT NULL,

    -- ── Output file paths ─────────────────────────────────────────────────
    `output_file`       VARCHAR(512)    DEFAULT NULL,   -- .aln (Clustal)
    `clustal_file`      VARCHAR(512)    DEFAULT NULL,   -- .clustal

    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`alignment_id`),
    KEY `idx_alignments_job_id` (`job_id`),

    CONSTRAINT `fk_alignments_jobs`
        FOREIGN KEY (`job_id`)
        REFERENCES `jobs` (`job_id`)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Multiple sequence alignment metadata for a job.';


-- ---------------------------------------------------------------------------
-- 6. TABLE: conservation_scores
--    Per-column conservation data. One row per alignment column.
--    Large table for long alignments — may contain thousands of rows per job.
--    Bulk-inserted inside a PDO transaction for performance.
-- ---------------------------------------------------------------------------

CREATE TABLE `conservation_scores` (
    `score_id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `alignment_id`          INT UNSIGNED    NOT NULL,

    -- 1-based column index in the alignment.
    `position`              INT UNSIGNED    NOT NULL,

    -- Shannon entropy–derived conservation score: 0.0 (variable) to 1.0 (conserved).
    `conservation_score`    FLOAT           NOT NULL DEFAULT 0.0,

    -- Fraction of sequences that have a gap at this position (0.0–1.0).
    `gap_fraction`          FLOAT           NOT NULL DEFAULT 0.0,

    PRIMARY KEY (`score_id`),

    -- Primary access pattern: fetch all scores for an alignment in order.
    KEY `idx_scores_alignment_pos` (`alignment_id`, `position`),

    CONSTRAINT `fk_scores_alignments`
        FOREIGN KEY (`alignment_id`)
        REFERENCES `alignments` (`alignment_id`)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-column conservation scores for a multiple sequence alignment.';


-- ---------------------------------------------------------------------------
-- 7. TABLE: motif_hits
--    One row per PROSITE motif match found by EMBOSS patmatmotifs.
--    A sequence may have multiple hits from the same or different motifs.
-- ---------------------------------------------------------------------------

CREATE TABLE `motif_hits` (
    `hit_id`        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `job_id`        INT UNSIGNED    NOT NULL,
    `seq_id`        INT UNSIGNED    NOT NULL,

    -- PROSITE pattern identifier, e.g. PS00390
    `motif_id`      VARCHAR(20)     NOT NULL DEFAULT '',

    -- Human-readable motif name, e.g. 'GLUCOSE_6_PHOSPHATASE'
    `motif_name`    VARCHAR(255)    NOT NULL DEFAULT '',

    -- Position range within the sequence (1-based, inclusive).
    `start_pos`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `end_pos`       INT UNSIGNED    NOT NULL DEFAULT 0,

    -- Match score where available (NULL for binary pattern matches).
    `score`         FLOAT           DEFAULT NULL,

    PRIMARY KEY (`hit_id`),

    KEY `idx_motif_hits_job_id`   (`job_id`),
    KEY `idx_motif_hits_seq_id`   (`seq_id`),
    KEY `idx_motif_hits_motif_id` (`motif_id`),

    CONSTRAINT `fk_motif_hits_jobs`
        FOREIGN KEY (`job_id`)
        REFERENCES `jobs` (`job_id`)
        ON DELETE CASCADE,

    CONSTRAINT `fk_motif_hits_sequences`
        FOREIGN KEY (`seq_id`)
        REFERENCES `sequences` (`seq_id`)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='PROSITE motif hits from EMBOSS patmatmotifs, one row per match.';


-- ---------------------------------------------------------------------------
-- 8. TABLE: blast_results
--    Top BLAST hits per query sequence (all-vs-all within the job dataset
--    or against NCBI nr). Populated by pipeline.py --task blast.
-- ---------------------------------------------------------------------------

CREATE TABLE `blast_results` (
    `blast_id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `job_id`            INT UNSIGNED    NOT NULL,

    -- NCBI accessions for query and hit sequences.
    `query_accession`   VARCHAR(50)     NOT NULL DEFAULT '',
    `hit_accession`     VARCHAR(50)     NOT NULL DEFAULT '',

    `hit_description`   TEXT            DEFAULT NULL,
    `hit_organism`      VARCHAR(255)    DEFAULT NULL,

    -- BLAST statistics
    `pct_identity`      FLOAT           DEFAULT NULL,  -- % identical positions
    `evalue`            DOUBLE          DEFAULT NULL,  -- expect value
    `bitscore`          FLOAT           DEFAULT NULL,

    PRIMARY KEY (`blast_id`),

    KEY `idx_blast_job_id`          (`job_id`),
    KEY `idx_blast_query_evalue`    (`job_id`, `query_accession`, `evalue`),

    CONSTRAINT `fk_blast_jobs`
        FOREIGN KEY (`job_id`)
        REFERENCES `jobs` (`job_id`)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='BLAST search results (all-vs-all or vs nr) for a job.';


-- ---------------------------------------------------------------------------
-- 9. TABLE: extra_analyses
--    Results from supplementary analysis tasks (pepstats, garnier,
--    pepwindow, etc.). result_summary stores key metrics as JSON;
--    output_file points to the full EMBOSS output on disk.
--    One row per (job, sequence, analysis_type) combination.
-- ---------------------------------------------------------------------------

CREATE TABLE `extra_analyses` (
    `extra_id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `job_id`            INT UNSIGNED    NOT NULL,

    -- Nullable: some analyses operate at job level rather than per-sequence
    -- (e.g. a job-wide report). Per-sequence analyses always set this.
    `seq_id`            INT UNSIGNED    DEFAULT NULL,

    -- Analysis type identifier matching the pipeline.py --task values.
    -- e.g. 'pepstats', 'garnier', 'pepwindow', 'uniprot', 'pdb', 'report'
    `analysis_type`     VARCHAR(50)     NOT NULL DEFAULT '',

    -- Key metrics stored as a JSON object for fast table rendering in PHP.
    -- e.g. {"mw":40345.23, "pi":8.74, "gravy":-0.056}
    `result_summary`    JSON            DEFAULT NULL,

    -- Absolute path to the full output file on disk (may be NULL if no file).
    `output_file`       VARCHAR(512)    DEFAULT NULL,

    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`extra_id`),

    -- Prevent duplicate analysis entries for the same job+seq+type combination.
    -- ON DUPLICATE KEY UPDATE used in PHP inserts for idempotent re-runs.
    UNIQUE KEY `uq_extra_job_seq_type` (`job_id`, `seq_id`, `analysis_type`),

    KEY `idx_extra_job_id`   (`job_id`),
    KEY `idx_extra_seq_id`   (`seq_id`),
    KEY `idx_extra_type`     (`analysis_type`),

    CONSTRAINT `fk_extra_jobs`
        FOREIGN KEY (`job_id`)
        REFERENCES `jobs` (`job_id`)
        ON DELETE CASCADE,

    CONSTRAINT `fk_extra_sequences`
        FOREIGN KEY (`seq_id`)
        REFERENCES `sequences` (`seq_id`)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Supplementary analysis results (pepstats, garnier, etc.) per sequence.';


-- ---------------------------------------------------------------------------
-- 10. TABLE: external_links
--     Cross-references to external databases (UniProt, PDB, AlphaFold,
--     KEGG) for individual sequences. One row per (sequence, database) pair.
-- ---------------------------------------------------------------------------

CREATE TABLE `external_links` (
    `link_id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `seq_id`                INT UNSIGNED    NOT NULL,

    -- Name of the external database: 'UniProt', 'PDB', 'AlphaFold', 'KEGG'
    `database_name`         VARCHAR(50)     NOT NULL DEFAULT '',

    -- Accession / identifier in the external database.
    -- e.g. 'P35575' (UniProt), '6EPC' (PDB), 'AF-Q9NQR9' (AlphaFold)
    `external_id`           VARCHAR(100)    NOT NULL DEFAULT '',

    -- Direct URL to the external record for embedding as a hyperlink.
    `url`                   VARCHAR(500)    DEFAULT NULL,

    -- Brief human-readable annotation: function, resolution, method, etc.
    `annotation_summary`    TEXT            DEFAULT NULL,

    PRIMARY KEY (`link_id`),

    -- Prevent duplicate links for the same sequence and external record.
    UNIQUE KEY `uq_links_seq_db_id` (`seq_id`, `database_name`, `external_id`),

    KEY `idx_links_seq_id`  (`seq_id`),
    KEY `idx_links_db_name` (`database_name`),

    CONSTRAINT `fk_links_sequences`
        FOREIGN KEY (`seq_id`)
        REFERENCES `sequences` (`seq_id`)
        ON DELETE CASCADE

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='External database cross-references (UniProt, PDB, AlphaFold) per sequence.';


-- =============================================================================
-- VIEWS
-- =============================================================================

-- ---------------------------------------------------------------------------
-- VIEW: v_job_summary
-- Used by: pages/revisit.php — renders job cards on the My Sessions page.
-- Returns one summary row per job with pre-aggregated counts to avoid
-- multiple round-trip queries in PHP.
-- ---------------------------------------------------------------------------

CREATE VIEW `v_job_summary` AS
SELECT
    j.job_id,
    j.session_token,
    j.label,
    j.protein_family,
    j.taxonomic_group,
    j.status,
    j.is_example,
    j.num_sequences,
    j.created_at,
    j.completed_at,

    -- Alignment statistics (NULL if alignment not yet run)
    a.alignment_id,
    a.tool_used         AS alignment_tool,
    a.alignment_length,
    ROUND(a.avg_identity, 2)    AS avg_identity,

    -- Total number of non-excluded sequences for this job
    COALESCE(sq.included_count, 0)  AS included_seqs,

    -- Number of distinct PROSITE motifs found (0 if not yet scanned)
    COALESCE(mh.distinct_motifs, 0) AS distinct_motifs,

    -- Total motif hits (0 if not yet scanned)
    COALESCE(mh.total_hits, 0)      AS motif_count,

    -- Mean conservation score across the alignment (NULL if not computed)
    ROUND(cs.mean_score, 4)         AS mean_conservation

FROM `jobs` j

-- Latest alignment for this job (there should be at most one, but LEFT JOIN
-- is safe for jobs where alignment has not yet been run)
LEFT JOIN `alignments` a
    ON a.job_id = j.job_id

-- Non-excluded sequence count
LEFT JOIN (
    SELECT
        job_id,
        COUNT(*) AS included_count
    FROM   `sequences`
    WHERE  excluded = 0
    GROUP  BY job_id
) sq ON sq.job_id = j.job_id

-- Motif summary aggregated to job level
LEFT JOIN (
    SELECT
        job_id,
        COUNT(DISTINCT motif_id) AS distinct_motifs,
        COUNT(*)                 AS total_hits
    FROM   `motif_hits`
    GROUP  BY job_id
) mh ON mh.job_id = j.job_id

-- Mean conservation score (requires joining through alignments)
LEFT JOIN (
    SELECT
        al.job_id,
        AVG(cs.conservation_score) AS mean_score
    FROM   `conservation_scores` cs
    JOIN   `alignments` al
        ON al.alignment_id = cs.alignment_id
    GROUP  BY al.job_id
) cs ON cs.job_id = j.job_id

-- Exclude deleted jobs from the summary view
WHERE j.status != 'deleted';


-- ---------------------------------------------------------------------------
-- VIEW: v_motif_overview
-- Used by: pages/motifs.php — renders the motif summary table.
-- Aggregates motif_hits to one row per (job, motif) with coverage stats.
-- ---------------------------------------------------------------------------

CREATE VIEW `v_motif_overview` AS
SELECT
    mh.job_id,
    mh.motif_id,
    mh.motif_name,

    -- How many distinct sequences carry this motif
    COUNT(DISTINCT mh.seq_id)   AS seq_count,

    -- Total occurrences (a motif can appear more than once per sequence)
    COUNT(*)                    AS total_hits,

    -- Positional range across all sequences
    MIN(mh.start_pos)           AS earliest_pos,
    MAX(mh.end_pos)             AS latest_pos,

    -- Mean hit length
    ROUND(AVG(mh.end_pos - mh.start_pos + 1), 1)   AS avg_hit_length,

    -- Number of non-excluded sequences in the job (for coverage calculation)
    sq.included_count           AS total_seqs

FROM `motif_hits` mh

-- Join to get the total sequence count for the job
JOIN (
    SELECT
        job_id,
        COUNT(*) AS included_count
    FROM   `sequences`
    WHERE  excluded = 0
    GROUP  BY job_id
) sq ON sq.job_id = mh.job_id

GROUP BY
    mh.job_id,
    mh.motif_id,
    mh.motif_name,
    sq.included_count

ORDER BY
    seq_count DESC,
    mh.motif_name ASC;


-- ---------------------------------------------------------------------------
-- VIEW: v_conservation_overview
-- Used by: pages/analysis.php, pages/revisit.php — summary stat cards.
-- Provides min/max/mean conservation and count of highly-conserved columns
-- per job, avoiding repeated aggregation queries in PHP.
-- ---------------------------------------------------------------------------

CREATE VIEW `v_conservation_overview` AS
SELECT
    al.job_id,
    al.alignment_id,
    al.num_sequences,
    al.alignment_length,
    ROUND(al.avg_identity, 2)           AS avg_identity,

    -- Conservation score aggregates over all alignment columns
    ROUND(MIN(cs.conservation_score), 4)    AS min_score,
    ROUND(MAX(cs.conservation_score), 4)    AS max_score,
    ROUND(AVG(cs.conservation_score), 4)    AS avg_score,

    -- Count of columns with conservation score >= 0.8 (highly conserved)
    SUM(CASE
        WHEN cs.conservation_score >= 0.8 THEN 1
        ELSE 0
    END)                                AS highly_conserved_cols,

    -- Count of columns with conservation score < 0.5 (variable)
    SUM(CASE
        WHEN cs.conservation_score < 0.5 THEN 1
        ELSE 0
    END)                                AS variable_cols,

    COUNT(cs.score_id)                  AS total_cols

FROM `alignments` al
JOIN `conservation_scores` cs
    ON cs.alignment_id = al.alignment_id
GROUP BY
    al.job_id,
    al.alignment_id,
    al.num_sequences,
    al.alignment_length,
    al.avg_identity;


-- =============================================================================
-- Re-enable foreign key checks
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 1;


-- =============================================================================
-- Verification query (optional — comment out in production)
-- Shows all created tables and views with their row counts / definitions.
-- =============================================================================

/*
SELECT
    table_name,
    table_type,
    table_rows,
    table_comment
FROM   information_schema.tables
WHERE  table_schema = DATABASE()
ORDER  BY table_type DESC, table_name ASC;
*/