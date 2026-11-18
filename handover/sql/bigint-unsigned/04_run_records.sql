-- OPTIONAL: run recording for the matcher dashboard (../integration-guide.md, G8).
-- Matching works without these tables. Run this script only if you build G8.
--
-- analytics_outputs  one row per /match call your app makes for a gig: the outcome,
--                    where the time went (the response's meta.timing_ms, and your
--                    app's own timings if it measures them), and meta.machine.
-- run_outputs        one row per result of that call: rank, score and the
--                    model's signals, flattened from the response.
--
-- Written by YOUR app after the matches page has been sent (team31-app:
-- App\Libraries\MatchRecorder). The engine never reads or writes them.
-- Times are milliseconds; a column your app doesn't measure stays NULL.
--
-- `gigs` is your own table: if it is named differently, change the REFERENCES
-- clause below (../README.md, Step 1). This variant is for gig ids that are
-- BIGINT UNSIGNED.

CREATE TABLE `analytics_outputs` (
  `id`                     int(11) unsigned NOT NULL AUTO_INCREMENT,
  `gig_id`                 bigint(20) unsigned NOT NULL,
  `user_id`                bigint(20) unsigned DEFAULT NULL,  -- the gig's owner (no foreign key: history outlives accounts)
  `status`                 varchar(10) NOT NULL,              -- ok | error
  `error`                  text DEFAULT NULL,                 -- the failure's message when status = error
  `matcher_machine`        varchar(200) DEFAULT NULL,         -- meta.machine
  `candidate_pool`         int(11) DEFAULT NULL,              -- meta.candidate_pool
  `returned`               int(11) DEFAULT NULL,              -- meta.returned
  `matcher_vector_read_ms` float DEFAULT NULL,                -- meta.timing_ms.vector_read
  `matcher_embed_ms`       float DEFAULT NULL,                -- meta.timing_ms.embed (NULL: the stored vector was reused)
  `matcher_candidates_ms`  float DEFAULT NULL,                -- meta.timing_ms.candidates
  `matcher_score_ms`       float DEFAULT NULL,                -- meta.timing_ms.score
  `matcher_response_ms`    float DEFAULT NULL,                -- meta.timing_ms.response
  `matcher_total_ms`       float DEFAULT NULL,                -- meta.timing_ms.total
  `matcher_call_ms`        float DEFAULT NULL,                -- your app: the whole HTTP call to /match
  `save_connect_ms`        float DEFAULT NULL,                -- your app: the request that saved the gig ...
  `save_query_ms`          float DEFAULT NULL,
  `save_query_count`       int(11) DEFAULT NULL,
  `save_php_ms`            float DEFAULT NULL,
  `save_total_ms`          float DEFAULT NULL,
  `db_connect_ms`          float DEFAULT NULL,                -- your app: the matches page request ...
  `db_query_ms`            float DEFAULT NULL,
  `db_query_count`         int(11) DEFAULT NULL,
  `php_views_ms`           float DEFAULT NULL,
  `page_total_ms`          float DEFAULT NULL,
  `action_total_ms`        float DEFAULT NULL,                -- your app: from Submit to the matches page (NULL on a revisit)
  `action_chain`           varchar(255) DEFAULT NULL,         -- e.g. "POST /create-gig -> GET /gigs/42/matches"
  `created_at`             datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `analytics_outputs_gig_id` (`gig_id`),
  CONSTRAINT `analytics_outputs_gig_id_foreign` FOREIGN KEY (`gig_id`) REFERENCES `gigs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `run_outputs` (
  `id`                  int(11) unsigned NOT NULL AUTO_INCREMENT,
  `analytics_output_id` int(11) unsigned NOT NULL,
  `gig_id`              bigint(20) unsigned NOT NULL,
  `rank`                int(11) NOT NULL,
  `provider_id`         bigint(20) unsigned NOT NULL,     -- results[].provider_id (no foreign key: history outlives profiles)
  `score`               float NOT NULL,
  `raw_margin`          float NOT NULL,
  `display_title`       varchar(500) DEFAULT NULL,  -- results[].provider.*
  `industry`            varchar(255) DEFAULT NULL,
  `bio_excerpt`         text DEFAULT NULL,
  -- results[].signals.retrieval, .business_fit and .taxonomy, one column each
  `bm25_score`          float DEFAULT NULL,
  `bm25_rank`           float DEFAULT NULL,
  `dense_cosine`        float DEFAULT NULL,
  `dense_rank`          float DEFAULT NULL,
  `rrf_score`           float DEFAULT NULL,
  `rrf_rank`            float DEFAULT NULL,
  `budget_fit`          float DEFAULT NULL,
  `seniority_fit`       float DEFAULT NULL,
  `avail_immediacy`     float DEFAULT NULL,
  `tag_level`           float DEFAULT NULL,
  `has_tags_gig`        float DEFAULT NULL,
  `has_tags_prov`       float DEFAULT NULL,
  `cat_any`             float DEFAULT NULL,
  `spec_cov`            float DEFAULT NULL,
  `skill_cov`           float DEFAULT NULL,
  `skill_idf_cov`       float DEFAULT NULL,
  `skill_soft`          float DEFAULT NULL,
  `created_at`          datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_outputs_gig_id` (`gig_id`),
  CONSTRAINT `run_outputs_analytics_output_id_foreign` FOREIGN KEY (`analytics_output_id`) REFERENCES `analytics_outputs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
