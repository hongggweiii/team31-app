-- Embedding vectors, stored with MariaDB Vector. Requires MariaDB 11.7 or
-- later (VECTOR type, VEC_FromText / VEC_ToText, VECTOR INDEX); we run 11.8.
--
-- showcase_embeddings  written by YOUR app (after calling embedding-service
--                      POST /embed/providers); read by the matcher, which ranks
--                      only showcases that have a row here.
-- gig_embeddings       written by the matcher after each /match response (the
--                      gig's vector, for reuse and analysis). Your app never
--                      needs to write it.
--
-- One table per side because MariaDB allows one VECTOR INDEX per table, and
-- gigs are embedded differently (query side, with an instruction prefix).
-- A row exists only once embedded: the indexed column must be NOT NULL.
--
-- Columns (see ../integration-guide.md for how to fill them):
--   model        the embedding model name embedding-service returns in "model"
--                (mixedbread-ai/mxbai-embed-large-v1). Never mix models: after a
--                model change, re-embed every row.
--   text_hash    sha256 (hex) of the exact text that was embedded, so a
--                re-embedding job can skip rows whose text has not changed.
--   fields_json  the ranker-format record that was embedded, as JSON. The
--                matcher reads it for BM25 and the exp_years feature: a
--                showcase row with fields_json NULL is never ranked.
--   embedding    the 1024 floats embedding-service returned, written with
--                VEC_FromText('[0.0123, -0.0456, ...]').
--
-- `showcases` and `gigs` are your own tables (see README.md, "Your tables").
-- If they are named differently, change the REFERENCES clauses below.
-- This variant is for tables whose `id` is BIGINT UNSIGNED (see ../README.md).

CREATE TABLE `showcase_embeddings` (
  `showcase_id` bigint(20) unsigned NOT NULL,
  `model`       varchar(100) NOT NULL,
  `text_hash`   char(64) NOT NULL,
  `fields_json` mediumtext DEFAULT NULL,
  `embedding`   vector(1024) NOT NULL,
  `created_at`  datetime DEFAULT NULL,
  `updated_at`  datetime DEFAULT NULL, -- set on every write: the matcher reloads when MAX(updated_at) changes
  PRIMARY KEY (`showcase_id`),
  VECTOR KEY `embedding` (`embedding`) M=16 DISTANCE=cosine,
  CONSTRAINT `showcase_embeddings_showcase_id_fk` FOREIGN KEY (`showcase_id`) REFERENCES `showcases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `gig_embeddings` (
  `gig_id`      bigint(20) unsigned NOT NULL,
  `model`       varchar(100) NOT NULL,
  `text_hash`   char(64) NOT NULL,
  `fields_json` mediumtext DEFAULT NULL,
  `embedding`   vector(1024) NOT NULL,
  `created_at`  datetime DEFAULT NULL,
  `updated_at`  datetime DEFAULT NULL,
  PRIMARY KEY (`gig_id`),
  VECTOR KEY `embedding` (`embedding`) M=16 DISTANCE=cosine,
  CONSTRAINT `gig_embeddings_gig_id_fk` FOREIGN KEY (`gig_id`) REFERENCES `gigs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
