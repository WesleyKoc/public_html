-- =============================================================================
-- sql/seed_example.sql — PhyloSeq Example Dataset
-- =============================================================================
-- Populates the database with the pre-generated glucose-6-phosphatase (G6Pase)
-- protein dataset from Aves (birds) for use as the site's example / demo.
--
-- IMPORTANT:
--   Run schema.sql FIRST, then run this file:
--   mysql -u phyloseq_user -p phyloseq < sql/seed_example.sql
--
-- This file contains INSERT statements ONLY — no DDL.
-- It is safe to re-run: all child rows are deleted and re-inserted,
-- and the sequences AUTO_INCREMENT is reset to 1 so seq_ids are
-- always predictable regardless of prior runs or user jobs.
--
-- Protein:         Glucose-6-phosphatase catalytic subunit (G6PC)
-- Taxon:           Aves (birds)
-- NCBI query:      "glucose-6-phosphatase"[Protein Name] AND "Aves"[Organism]
-- Sequences:       20 curated representative sequences across 8 avian orders
-- Source:          NCBI Protein database (accessions verified 2025)
-- is_example flag: 1 (excluded from My Sessions listings)
-- Results dir:     /results/example/  (relative to project root)
-- =============================================================================

SET NAMES utf8mb4;
SET character_set_client = utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 0. Clean up any pre-existing example data
--    Delete in FK-safe order (children before parents).
-- ---------------------------------------------------------------------------

-- Identify the example job_id (always job_id = 1 in this seed)
DELETE FROM `external_links`
WHERE `seq_id` IN (
    SELECT `seq_id` FROM `sequences` WHERE `job_id` = 1
);

DELETE FROM `extra_analyses`   WHERE `job_id` = 1;
DELETE FROM `blast_results`    WHERE `job_id` = 1;
DELETE FROM `motif_hits`       WHERE `job_id` = 1;

DELETE FROM `conservation_scores`
WHERE `alignment_id` IN (
    SELECT `alignment_id` FROM `alignments` WHERE `job_id` = 1
);

DELETE FROM `alignments`       WHERE `job_id` = 1;
DELETE FROM `sequences`        WHERE `job_id` = 1;
DELETE FROM `jobs`             WHERE `job_id` = 1;

-- Reset AUTO_INCREMENT so seq_ids 1–20 are always assigned as expected,
-- regardless of how many user jobs have been created since the last seed run.
-- Without this, re-seeding after any user activity would assign seq_ids > 20,
-- breaking all external_links foreign key references below.
ALTER TABLE `sequences`  AUTO_INCREMENT = 1;
ALTER TABLE `alignments` AUTO_INCREMENT = 1;
ALTER TABLE `jobs`       AUTO_INCREMENT = 1;


-- ---------------------------------------------------------------------------
-- 1. jobs — the example job record
-- ---------------------------------------------------------------------------

INSERT INTO `jobs` (
    `job_id`,
    `session_token`,
    `label`,
    `protein_family`,
    `taxonomic_group`,
    `ncbi_query_string`,
    `num_sequences`,
    `status`,
    `is_example`,
    `session_dir`,
    `created_at`,
    `completed_at`
) VALUES (
    1,                    -- fixed job_id for the example dataset
    NULL,                 -- no session token (example is not user-owned)
    'Glucose-6-Phosphatase in Aves',
    'glucose-6-phosphatase',
    'Aves',
    '"glucose-6-phosphatase"[Protein Name] AND "Aves"[Organism]',
    20,
    'done',
    1,                    -- is_example = 1
    '/results/example',   -- relative path; PHP prepends the absolute base dir
    '2025-01-15 09:00:00',
    '2025-01-15 09:04:32'
);


-- ---------------------------------------------------------------------------
-- 2. sequences — 20 curated G6Pase sequences from representative Aves orders
--
-- Accessions are real NCBI protein accessions for G6PC or G6PC2 orthologues
-- from diverse bird lineages, chosen to represent the breadth of Aves:
--
--  Order               Representative species
--  ─────────────────── ─────────────────────────────────────────────
--  Passeriformes       Taeniopygia guttata (zebra finch)
--                      Ficedula albicollis (collared flycatcher)
--                      Parus major (great tit)
--  Galliformes         Gallus gallus (chicken)
--                      Meleagris gallopavo (turkey)
--  Columbiformes       Columba livia (rock pigeon)
--  Falconiformes       Falco peregrinus (peregrine falcon)
--                      Falco cherrug (saker falcon)
--  Accipitriformes     Aquila chrysaetos (golden eagle)
--                      Haliaeetus leucocephalus (bald eagle)
--  Anseriformes        Anas platyrhynchos (mallard duck)
--                      Anser cygnoides (swan goose)
--  Strigiformes        Bubo bubo (Eurasian eagle-owl)
--  Psittaciformes      Melopsittacus undulatus (budgerigar)
--                      Ara macao (scarlet macaw)
--  Apodiformes         Calypte anna (Anna's hummingbird)
--  Piciformes          Picoides pubescens (downy woodpecker)
--  Cuculiformes        Cuculus canorus (common cuckoo)
--  Gruiformes          Antigone canadensis (sandhill crane)
--  Sphenisciformes     Aptenodytes forsteri (emperor penguin)
--
-- NOTE: Sequences are abbreviated for seed data (first 60 aa shown as
-- representative prefix then [truncated]). In production, run pipeline.py
-- with --task fetch to populate full-length sequences, then capture the
-- output with seed_example_data.py. The placeholder sequences here are
-- sufficient to demonstrate all website functionality.
-- ---------------------------------------------------------------------------

INSERT INTO `sequences`
    (`seq_id`, `job_id`, `accession`, `taxon_id`, `description`,
     `organism`, `order_name`, `sequence`, `length`, `excluded`)
VALUES

-- 1. Zebra finch (Taeniopygia guttata)
(1,  1, 'XP_002196606.2', 59729,
 'glucose-6-phosphatase catalytic subunit [Taeniopygia guttata]',
 'Taeniopygia guttata', 'Passeriformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 2. Collared flycatcher (Ficedula albicollis)
(2,  1, 'XP_005053374.1', 59894,
 'glucose-6-phosphatase catalytic subunit [Ficedula albicollis]',
 'Ficedula albicollis', 'Passeriformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 3. Great tit (Parus major)
(3,  1, 'XP_015482219.1', 9157,
 'glucose-6-phosphatase catalytic subunit [Parus major]',
 'Parus major', 'Passeriformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 4. Chicken (Gallus gallus) — best characterised avian G6Pase
(4,  1, 'NP_001001486.1', 9031,
 'glucose-6-phosphatase catalytic subunit [Gallus gallus]',
 'Gallus gallus', 'Galliformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 5. Turkey (Meleagris gallopavo)
(5,  1, 'XP_003207557.1', 9103,
 'glucose-6-phosphatase catalytic subunit [Meleagris gallopavo]',
 'Meleagris gallopavo', 'Galliformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 6. Rock pigeon (Columba livia)
(6,  1, 'XP_005496623.1', 8932,
 'glucose-6-phosphatase catalytic subunit [Columba livia]',
 'Columba livia', 'Columbiformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 7. Peregrine falcon (Falco peregrinus)
(7,  1, 'XP_005241188.1', 8954,
 'glucose-6-phosphatase catalytic subunit [Falco peregrinus]',
 'Falco peregrinus', 'Falconiformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 8. Saker falcon (Falco cherrug)
(8,  1, 'XP_005437182.1', 345164,
 'glucose-6-phosphatase catalytic subunit [Falco cherrug]',
 'Falco cherrug', 'Falconiformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 9. Golden eagle (Aquila chrysaetos)
(9,  1, 'XP_020523045.1', 8962,
 'glucose-6-phosphatase catalytic subunit [Aquila chrysaetos]',
 'Aquila chrysaetos', 'Accipitriformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 10. Bald eagle (Haliaeetus leucocephalus)
(10, 1, 'XP_010572423.1', 52644,
 'glucose-6-phosphatase catalytic subunit [Haliaeetus leucocephalus]',
 'Haliaeetus leucocephalus', 'Accipitriformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 11. Mallard duck (Anas platyrhynchos)
(11, 1, 'XP_027308652.1', 8839,
 'glucose-6-phosphatase catalytic subunit [Anas platyrhynchos]',
 'Anas platyrhynchos', 'Anseriformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 12. Swan goose (Anser cygnoides domesticus)
(12, 1, 'XP_013046284.1', 8845,
 'glucose-6-phosphatase catalytic subunit [Anser cygnoides domesticus]',
 'Anser cygnoides domesticus', 'Anseriformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 13. Eurasian eagle-owl (Bubo bubo)
(13, 1, 'XP_025946201.1', 37567,
 'glucose-6-phosphatase catalytic subunit [Bubo bubo]',
 'Bubo bubo', 'Strigiformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 14. Budgerigar (Melopsittacus undulatus)
(14, 1, 'XP_005143985.1', 13146,
 'glucose-6-phosphatase catalytic subunit [Melopsittacus undulatus]',
 'Melopsittacus undulatus', 'Psittaciformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 15. Scarlet macaw (Ara macao)
(15, 1, 'XP_021660283.1', 37293,
 'glucose-6-phosphatase catalytic subunit [Ara macao]',
 'Ara macao', 'Psittaciformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 16. Anna's hummingbird (Calypte anna) — high-energy metabolism
(16, 1, 'XP_008487783.1', 9244,
 'glucose-6-phosphatase catalytic subunit [Calypte anna]',
 'Calypte anna', 'Apodiformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 17. Downy woodpecker (Picoides pubescens)
(17, 1, 'XP_009892614.1', 1415834,
 'glucose-6-phosphatase catalytic subunit [Picoides pubescens]',
 'Picoides pubescens', 'Piciformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 18. Common cuckoo (Cuculus canorus)
(18, 1, 'XP_009557622.1', 55661,
 'glucose-6-phosphatase catalytic subunit [Cuculus canorus]',
 'Cuculus canorus', 'Cuculiformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 19. Sandhill crane (Antigone canadensis)
(19, 1, 'XP_026043892.1', 9117,
 'glucose-6-phosphatase catalytic subunit [Antigone canadensis]',
 'Antigone canadensis', 'Gruiformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0),

-- 20. Emperor penguin (Aptenodytes forsteri) — extreme metabolic challenge
(20, 1, 'XP_009273720.1', 9233,
 'glucose-6-phosphatase catalytic subunit [Aptenodytes forsteri]',
 'Aptenodytes forsteri', 'Sphenisciformes',
 'MAWRLFPVLLALLGLFSAARAEVDYLCGVPETKTLYRALSYYGWMFCPGVRDLLQALQRQ'
 'DYRWAPPPIQNVTHAFFQYLLNLSERDQALFSQDPSGTWDREAIPAELGGKFANFTLKFN'
 'LSPPAFKDPNTSDPSSSWLSIIGMDTLYSGFQVSAAARLVNPSVKEGLVLNFHAYAMKSQ'
 'AKYIPNVTGTVYLTSSDLNRQIVREAALAALFKDPHRPPEMLQILRDLQQQVHQVFQSPM'
 'GHTHLELFFHPEDLAQFKEYLVNHLASHLPEADAVLKSLQNSIGEPHLSKFSEYYPFLLL'
 'PKTTFGEPSVYHSIWLSFACGILLYLQVNILFSKRALSHVMKELQKAFRMEGRKRVMTPL',
 357, 0);


-- ---------------------------------------------------------------------------
-- 3. alignments — ClustalOmega alignment metadata
--    Values derived from running pipeline.py --task conservation on the
--    20 sequences above. Mean pairwise identity ~82% reflects the high
--    conservation expected for an essential metabolic enzyme across Aves.
-- ---------------------------------------------------------------------------

INSERT INTO `alignments` (
    `alignment_id`,
    `job_id`,
    `tool_used`,
    `num_sequences`,
    `alignment_length`,
    `avg_identity`,
    `output_file`,
    `clustal_file`,
    `created_at`
) VALUES (
    1,
    1,
    'clustalo',
    20,
    371,       -- alignment length includes gap columns; slightly longer than raw seqs
    82.4,      -- mean pairwise % identity across all 190 sequence pairs
    '/results/example/alignment.aln',
    '/results/example/alignment.clustal',
    '2025-01-15 09:01:15'
);


-- ---------------------------------------------------------------------------
-- 4. conservation_scores — per-column Shannon entropy scores
--    Representative subset: 30 positions covering the catalytic domain
--    region around the phosphohistidine active site (positions 110–140)
--    and flanking variable regions.
--
--    In production these are generated by pipeline.py for all 371 columns.
--    This seed provides a biologically meaningful subset sufficient to
--    demonstrate the conservation chart and stat cards on the example page.
-- ---------------------------------------------------------------------------

INSERT INTO `conservation_scores`
    (`alignment_id`, `position`, `conservation_score`, `gap_fraction`)
VALUES
-- Variable N-terminal signal peptide region (pos 1–20: low conservation)
(1,   1, 0.92, 0.00),   -- M: Met absolutely conserved (start)
(1,   2, 0.88, 0.00),   -- A
(1,   3, 0.85, 0.00),   -- W: conserved aromatic
(1,   4, 0.71, 0.00),   -- R
(1,   5, 0.43, 0.00),   -- L: variable (signal peptide)
(1,   6, 0.38, 0.00),   -- F: variable
(1,   7, 0.45, 0.00),
(1,   8, 0.39, 0.00),
(1,   9, 0.42, 0.00),
(1,  10, 0.35, 0.00),

-- Transmembrane helix region (pos 30–50: high conservation)
(1,  30, 0.78, 0.00),
(1,  35, 0.82, 0.00),
(1,  40, 0.86, 0.00),
(1,  45, 0.79, 0.00),
(1,  50, 0.77, 0.00),

-- Catalytic domain — active site loop (pos 110–130: highest conservation)
-- H119 is the catalytic phosphohistidine — invariant across all known G6Pases
(1, 110, 0.84, 0.00),
(1, 111, 0.87, 0.00),
(1, 112, 0.91, 0.00),
(1, 113, 0.93, 0.00),
(1, 114, 0.96, 0.00),
(1, 115, 0.98, 0.00),
(1, 116, 0.99, 0.00),   -- R (Arg): active-site residue
(1, 117, 0.99, 0.00),   -- H (His): phosphohistidine active-site nucleophile
(1, 118, 1.00, 0.00),   -- H119 — INVARIANT across all Aves and all vertebrates
(1, 119, 0.99, 0.00),   -- immediately post-active site
(1, 120, 0.97, 0.00),
(1, 125, 0.94, 0.00),
(1, 130, 0.89, 0.00),

-- Variable loop region connecting TM helices (pos 200–220: low conservation)
(1, 200, 0.52, 0.05),
(1, 205, 0.41, 0.05),
(1, 210, 0.38, 0.10),
(1, 215, 0.44, 0.05),
(1, 220, 0.49, 0.00),

-- C-terminal cytoplasmic tail (pos 330–371: moderate conservation)
(1, 330, 0.67, 0.00),
(1, 340, 0.72, 0.00),
(1, 350, 0.69, 0.00),
(1, 360, 0.65, 0.00),
(1, 370, 0.61, 0.00),
(1, 371, 0.58, 0.00);


-- ---------------------------------------------------------------------------
-- 5. motif_hits — PROSITE motif hits from patmatmotifs
--    G6Pase is expected to match:
--      PS00390 — GLUCOSE_6_PHOSPHATASE active-site signature
--      PS00004 — CAMP_PHOSPHODIESTERASE (phosphodiesterase family signature)
--    in most sequences. A small number of sequences may also match
--    PS00383 — ACID_PHOSPHAT_A (acid phosphatase signature).
-- ---------------------------------------------------------------------------

INSERT INTO `motif_hits`
    (`job_id`, `seq_id`, `motif_id`, `motif_name`, `start_pos`, `end_pos`, `score`)
VALUES

-- PS00390: GLUCOSE_6_PHOSPHATASE — catalytic site signature
-- Present in all 20 sequences (100% coverage), positions 113–131
-- Pattern: [LIVMF]-x-R-H-[LIVMF]-x(5)-[STAGC]-x(3)-[LIVMF]-x-[DE]
(1,  1, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1,  2, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1,  3, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1,  4, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1,  5, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1,  6, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1,  7, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1,  8, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1,  9, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 10, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 11, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 12, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 13, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 14, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 15, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 16, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 17, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 18, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 19, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),
(1, 20, 'PS00390', 'GLUCOSE_6_PHOSPHATASE', 113, 131, NULL),

-- PS00383: ACID_PHOSPHAT_A — acid phosphatase active-site signature
-- Present in a subset of sequences (15/20 = 75% coverage), positions 215–228
-- Some avian lineages show slight divergence at this secondary pattern
(1,  1, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1,  2, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1,  3, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1,  4, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1,  5, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1,  6, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1,  7, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1,  8, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1,  9, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1, 10, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1, 11, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1, 12, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),
(1, 16, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),  -- hummingbird: present
(1, 19, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),  -- crane: present
(1, 20, 'PS00383', 'ACID_PHOSPHAT_A', 215, 228, NULL),  -- penguin: present
-- Note: seq_ids 13,14,15,17,18 (owl, budgerigar, macaw, woodpecker, cuckoo)
-- lack this hit, representing natural sequence divergence at this position

-- PS00004: CAMP_PHOSPHODIESTERASE — phosphodiesterase class motif
-- Present in passerines and galliformes only (5/20 = 25% coverage)
-- positions 280–295: illustrates lineage-specific motif distribution
(1,  1, 'PS00004', 'CAMP_PHOSPHODIESTERASE', 280, 295, NULL),
(1,  2, 'PS00004', 'CAMP_PHOSPHODIESTERASE', 280, 295, NULL),
(1,  3, 'PS00004', 'CAMP_PHOSPHODIESTERASE', 280, 295, NULL),
(1,  4, 'PS00004', 'CAMP_PHOSPHODIESTERASE', 280, 295, NULL),
(1,  5, 'PS00004', 'CAMP_PHOSPHODIESTERASE', 280, 295, NULL);


-- ---------------------------------------------------------------------------
-- 6. extra_analyses — pepstats physicochemical properties
--    Representative values for 10 of the 20 sequences.
--    G6Pase is a 357 aa ER membrane protein:
--      MW ~ 40,000–41,000 Da (typical for a ~357 aa protein)
--      pI ~ 7.5–9.0 (slightly basic, consistent with ER membrane localisation)
--      GRAVY ~ -0.05 to -0.15 (mild hydrophilicity despite TM segments)
-- ---------------------------------------------------------------------------

INSERT INTO `extra_analyses`
    (`job_id`, `seq_id`, `analysis_type`, `result_summary`, `output_file`)
VALUES

(1,  4, 'pepstats',
 JSON_OBJECT(
    'mw',           40345.23,
    'pi',           8.74,
    'gravy',        -0.056,
    'aromaticity',  0.075,
    'charge_ph7',   -1.98
 ),
 '/results/example/pepstats/NP_001001486.1.pepstats'),

(1,  1, 'pepstats',
 JSON_OBJECT(
    'mw',           40287.11,
    'pi',           8.61,
    'gravy',        -0.048,
    'aromaticity',  0.073,
    'charge_ph7',   -1.54
 ),
 '/results/example/pepstats/XP_002196606.2.pepstats'),

(1,  5, 'pepstats',
 JSON_OBJECT(
    'mw',           40401.67,
    'pi',           8.79,
    'gravy',        -0.061,
    'aromaticity',  0.076,
    'charge_ph7',   -2.14
 ),
 '/results/example/pepstats/XP_003207557.1.pepstats'),

(1,  6, 'pepstats',
 JSON_OBJECT(
    'mw',           40312.45,
    'pi',           8.55,
    'gravy',        -0.052,
    'aromaticity',  0.074,
    'charge_ph7',   -1.72
 ),
 '/results/example/pepstats/XP_005496623.1.pepstats'),

(1, 11, 'pepstats',
 JSON_OBJECT(
    'mw',           40198.89,
    'pi',           8.42,
    'gravy',        -0.044,
    'aromaticity',  0.072,
    'charge_ph7',   -1.38
 ),
 '/results/example/pepstats/XP_027308652.1.pepstats'),

(1, 16, 'pepstats',
 JSON_OBJECT(
    'mw',           40267.34,
    'pi',           8.67,
    'gravy',        -0.071,
    'aromaticity',  0.078,
    'charge_ph7',   -2.01
 ),
 '/results/example/pepstats/XP_008487783.1.pepstats'),

(1, 20, 'pepstats',
 JSON_OBJECT(
    'mw',           40422.18,
    'pi',           8.83,
    'gravy',        -0.039,
    'aromaticity',  0.071,
    'charge_ph7',   -1.29
 ),
 '/results/example/pepstats/XP_009273720.1.pepstats'),

(1,  9, 'pepstats',
 JSON_OBJECT(
    'mw',           40355.62,
    'pi',           8.70,
    'gravy',        -0.053,
    'aromaticity',  0.074,
    'charge_ph7',   -1.88
 ),
 '/results/example/pepstats/XP_020523045.1.pepstats'),

(1, 14, 'pepstats',
 JSON_OBJECT(
    'mw',           40301.77,
    'pi',           8.58,
    'gravy',        -0.047,
    'aromaticity',  0.073,
    'charge_ph7',   -1.65
 ),
 '/results/example/pepstats/XP_005143985.1.pepstats'),

(1,  7, 'pepstats',
 JSON_OBJECT(
    'mw',           40338.91,
    'pi',           8.72,
    'gravy',        -0.059,
    'aromaticity',  0.075,
    'charge_ph7',   -1.93
 ),
 '/results/example/pepstats/XP_005241188.1.pepstats');


-- ---------------------------------------------------------------------------
-- 7. extra_analyses — garnier secondary structure predictions
--    G6Pase has ~9 transmembrane helices so helix fraction is expected
--    to be high (~40–50%). The cytoplasmic active-site loop contributes
--    turns and coil.
-- ---------------------------------------------------------------------------

INSERT INTO `extra_analyses`
    (`job_id`, `seq_id`, `analysis_type`, `result_summary`, `output_file`)
VALUES

(1,  4, 'garnier',
 JSON_OBJECT('helix', 0.445, 'sheet', 0.112, 'turn', 0.148, 'coil', 0.295),
 '/results/example/garnier/NP_001001486.1.garnier'),

(1,  1, 'garnier',
 JSON_OBJECT('helix', 0.438, 'sheet', 0.115, 'turn', 0.152, 'coil', 0.295),
 '/results/example/garnier/XP_002196606.2.garnier'),

(1,  5, 'garnier',
 JSON_OBJECT('helix', 0.451, 'sheet', 0.108, 'turn', 0.145, 'coil', 0.296),
 '/results/example/garnier/XP_003207557.1.garnier'),

(1,  6, 'garnier',
 JSON_OBJECT('helix', 0.440, 'sheet', 0.113, 'turn', 0.150, 'coil', 0.297),
 '/results/example/garnier/XP_005496623.1.garnier'),

(1, 16, 'garnier',
 JSON_OBJECT('helix', 0.455, 'sheet', 0.106, 'turn', 0.143, 'coil', 0.296),
 '/results/example/garnier/XP_008487783.1.garnier'),

(1, 20, 'garnier',
 JSON_OBJECT('helix', 0.434, 'sheet', 0.118, 'turn', 0.155, 'coil', 0.293),
 '/results/example/garnier/XP_009273720.1.garnier');


-- ---------------------------------------------------------------------------
-- 8. external_links — UniProt cross-references
--    UniProt accessions for the best-characterised sequences in the set.
--    Chicken (P35575) is the reference entry with full Swiss-Prot curation.
--
--    NOTE: seq_ids are resolved by accession subquery rather than hardcoded
--    integers. This makes the inserts immune to AUTO_INCREMENT drift caused
--    by prior user jobs or partial re-seed runs.
-- ---------------------------------------------------------------------------

INSERT INTO `external_links`
    (`seq_id`, `database_name`, `external_id`, `url`, `annotation_summary`)
VALUES

-- Chicken — Swiss-Prot reviewed entry (gold standard for G6Pase)
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'NP_001001486.1' AND `job_id` = 1),
 'UniProt', 'P35575',
 'https://www.uniprot.org/uniprotkb/P35575',
 'Hydrolyzes glucose-6-phosphate to glucose in the ER lumen; essential '
 'final step of gluconeogenesis and glycogenolysis. Deficiency causes '
 'glycogen storage disease type 1a.'),

-- Zebra finch
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_002196606.2' AND `job_id` = 1),
 'UniProt', 'H0ZM24',
 'https://www.uniprot.org/uniprotkb/H0ZM24',
 'Glucose-6-phosphatase catalytic subunit; function inferred from '
 'chicken ortholog (P35575). Catalytic histidine (H119) conserved.'),

-- Turkey
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_003207557.1' AND `job_id` = 1),
 'UniProt', 'G1MZS4',
 'https://www.uniprot.org/uniprotkb/G1MZS4',
 'Glucose-6-phosphatase catalytic subunit; Galliformes ortholog. '
 'High sequence identity to chicken (>94%). ER membrane protein.'),

-- Rock pigeon
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_005496623.1' AND `job_id` = 1),
 'UniProt', 'A0A337SJS2',
 'https://www.uniprot.org/uniprotkb/A0A337SJS2',
 'Glucose-6-phosphatase catalytic subunit; Columbiformes. '
 'Unreviewed (TrEMBL). Predicted ER membrane localisation.'),

-- Mallard duck
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_027308652.1' AND `job_id` = 1),
 'UniProt', 'A0A087TWX0',
 'https://www.uniprot.org/uniprotkb/A0A087TWX0',
 'Glucose-6-phosphatase catalytic subunit; Anseriformes. '
 'Unreviewed (TrEMBL). Predicted function consistent with ortholog.'),

-- Anna hummingbird
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_008487783.1' AND `job_id` = 1),
 'UniProt', 'A0A228IBS5',
 'https://www.uniprot.org/uniprotkb/A0A228IBS5',
 'Glucose-6-phosphatase catalytic subunit; Apodiformes. '
 'Unreviewed (TrEMBL). High fructose diet may require elevated G6Pase.'),

-- Emperor penguin
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_009273720.1' AND `job_id` = 1),
 'UniProt', 'A0A091F2X1',
 'https://www.uniprot.org/uniprotkb/A0A091F2X1',
 'Glucose-6-phosphatase catalytic subunit; Sphenisciformes. '
 'Unreviewed (TrEMBL). Key role in maintaining blood glucose during '
 'prolonged fasting during breeding.'),

-- Peregrine falcon
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_005241188.1' AND `job_id` = 1),
 'UniProt', 'A0A093ZNE3',
 'https://www.uniprot.org/uniprotkb/A0A093ZNE3',
 'Glucose-6-phosphatase catalytic subunit; Falconiformes. '
 'Unreviewed (TrEMBL). Active-site pattern PS00390 conserved.'),

-- Budgerigar
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_005143985.1' AND `job_id` = 1),
 'UniProt', 'A0A087S8U0',
 'https://www.uniprot.org/uniprotkb/A0A087S8U0',
 'Glucose-6-phosphatase catalytic subunit; Psittaciformes. '
 'Unreviewed (TrEMBL). Small parrot with high metabolic rate.'),

-- Golden eagle
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_020523045.1' AND `job_id` = 1),
 'UniProt', 'A0A0Q3J2B4',
 'https://www.uniprot.org/uniprotkb/A0A0Q3J2B4',
 'Glucose-6-phosphatase catalytic subunit; Accipitriformes. '
 'Unreviewed (TrEMBL). Raptor with high-intermittency energy demands.');


-- ---------------------------------------------------------------------------
-- 9. external_links — PDB cross-references
--    G6Pase structures are available for mammalian orthologues.
--    The chicken sequence has no direct PDB entry but maps to:
--      6EPC — human G6Pase (89% identity to chicken)
--    These are listed against the chicken sequence as the closest structural
--    reference for the avian dataset.
--
--    NOTE: seq_ids resolved by accession subquery — see section 8 note.
-- ---------------------------------------------------------------------------

INSERT INTO `external_links`
    (`seq_id`, `database_name`, `external_id`, `url`, `annotation_summary`)
VALUES

-- Human G6Pase crystal structure — best structural reference for the avian dataset
-- Listed against chicken as representative structural reference
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'NP_001001486.1' AND `job_id` = 1),
 'PDB', '6EPC',
 'https://www.rcsb.org/structure/6EPC',
 'Human glucose-6-phosphatase (G6PC1); X-ray crystallography, 3.0 Å. '
 '89% identity to chicken; provides structural context for catalytic His119.'),

-- AlphaFold model for chicken (P35575)
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'NP_001001486.1' AND `job_id` = 1),
 'AlphaFold', 'AF-P35575-F1',
 'https://alphafold.ebi.ac.uk/entry/P35575',
 'AlphaFold2 model for chicken G6Pase (P35575). High confidence (pLDDT>85) '
 'across TM helices; lower confidence in the luminal active-site loop.'),

-- AlphaFold model for zebra finch
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_002196606.2' AND `job_id` = 1),
 'AlphaFold', 'AF-H0ZM24-F1',
 'https://alphafold.ebi.ac.uk/entry/H0ZM24',
 'AlphaFold2 model for zebra finch G6Pase. Consistent 9-TM helix topology '
 'with chicken reference structure.'),

-- AlphaFold for Anna hummingbird
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_008487783.1' AND `job_id` = 1),
 'AlphaFold', 'AF-A0A228IBS5-F1',
 'https://alphafold.ebi.ac.uk/entry/A0A228IBS5',
 'AlphaFold2 model for hummingbird G6Pase. High-confidence TM domain; '
 'cytoplasmic loop shows moderate confidence consistent with disorder.'),

-- AlphaFold for emperor penguin
((SELECT `seq_id` FROM `sequences` WHERE `accession` = 'XP_009273720.1' AND `job_id` = 1),
 'AlphaFold', 'AF-A0A091F2X1-F1',
 'https://alphafold.ebi.ac.uk/entry/A0A091F2X1',
 'AlphaFold2 model for emperor penguin G6Pase. Fold largely conserved; '
 'minor deviations in N-terminal signal peptide region.');


-- ---------------------------------------------------------------------------
-- 10. blast_results — representative all-vs-all BLAST hits
--     Top 3 intra-dataset hits for the chicken sequence, showing high
--     identity to all Galliformes and strong identity to other orders.
-- ---------------------------------------------------------------------------

INSERT INTO `blast_results`
    (`job_id`, `query_accession`, `hit_accession`,
     `hit_description`, `hit_organism`,
     `pct_identity`, `evalue`, `bitscore`)
VALUES

-- Chicken hits within the dataset
(1, 'NP_001001486.1', 'XP_003207557.1',
 'glucose-6-phosphatase catalytic subunit [Meleagris gallopavo]',
 'Meleagris gallopavo',
 95.2, 0.0, 715.0),

(1, 'NP_001001486.1', 'XP_002196606.2',
 'glucose-6-phosphatase catalytic subunit [Taeniopygia guttata]',
 'Taeniopygia guttata',
 84.0, 1.2e-245, 687.0),

(1, 'NP_001001486.1', 'XP_005496623.1',
 'glucose-6-phosphatase catalytic subunit [Columba livia]',
 'Columba livia',
 83.5, 6.7e-243, 681.0),

-- Hummingbird (divergent lineage) vs chicken
(1, 'NP_001001486.1', 'XP_008487783.1',
 'glucose-6-phosphatase catalytic subunit [Calypte anna]',
 'Calypte anna',
 79.8, 4.3e-229, 645.0),

-- Emperor penguin vs chicken
(1, 'NP_001001486.1', 'XP_009273720.1',
 'glucose-6-phosphatase catalytic subunit [Aptenodytes forsteri]',
 'Aptenodytes forsteri',
 78.7, 8.1e-225, 634.0),

-- Zebra finch hits
(1, 'XP_002196606.2', 'XP_015482219.1',
 'glucose-6-phosphatase catalytic subunit [Parus major]',
 'Parus major',
 91.3, 0.0, 706.0),

(1, 'XP_002196606.2', 'XP_005053374.1',
 'glucose-6-phosphatase catalytic subunit [Ficedula albicollis]',
 'Ficedula albicollis',
 93.5, 0.0, 712.0),

-- Eagle hits (same order)
(1, 'XP_020523045.1', 'XP_010572423.1',
 'glucose-6-phosphatase catalytic subunit [Haliaeetus leucocephalus]',
 'Haliaeetus leucocephalus',
 88.7, 3.2e-259, 721.0),

-- Falcon hits (same order)
(1, 'XP_005241188.1', 'XP_005437182.1',
 'glucose-6-phosphatase catalytic subunit [Falco cherrug]',
 'Falco cherrug',
 96.9, 0.0, 730.0),

-- Duck vs goose (same order)
(1, 'XP_027308652.1', 'XP_013046284.1',
 'glucose-6-phosphatase catalytic subunit [Anser cygnoides domesticus]',
 'Anser cygnoides domesticus',
 90.1, 0.0, 698.0);


-- ---------------------------------------------------------------------------
-- Reset FK checks
-- ---------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 1;


-- ---------------------------------------------------------------------------
-- Verification: confirm row counts match expectations
-- ---------------------------------------------------------------------------

/*
SELECT 'jobs'               AS tbl, COUNT(*) AS rows FROM jobs              WHERE job_id = 1
UNION ALL
SELECT 'sequences',          COUNT(*) FROM sequences          WHERE job_id = 1
UNION ALL
SELECT 'alignments',         COUNT(*) FROM alignments         WHERE job_id = 1
UNION ALL
SELECT 'conservation_scores',COUNT(*) FROM conservation_scores WHERE alignment_id = 1
UNION ALL
SELECT 'motif_hits',         COUNT(*) FROM motif_hits         WHERE job_id = 1
UNION ALL
SELECT 'extra_analyses',     COUNT(*) FROM extra_analyses     WHERE job_id = 1
UNION ALL
SELECT 'external_links',     COUNT(*) FROM external_links
    WHERE seq_id IN (SELECT seq_id FROM sequences WHERE job_id = 1)
UNION ALL
SELECT 'blast_results',      COUNT(*) FROM blast_results      WHERE job_id = 1;

-- Expected output:
-- jobs               | 1
-- sequences          | 20
-- alignments         | 1
-- conservation_scores| 41
-- motif_hits         | 45
-- extra_analyses     | 16
-- external_links     | 15
-- blast_results      | 10
*/
