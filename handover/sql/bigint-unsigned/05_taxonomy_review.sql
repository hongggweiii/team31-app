-- OPTIONAL: for taxonomy-service (AI tag suggestion and the review of new terms,
-- ../integration-guide.md, G9-G11). Matching works without these tables. Run
-- this script only if you deploy taxonomy-service. Needs 01_taxonomy.sql first.
--
-- taxonomy_proposals  terms the taxonomy doesn't have yet, suggested by the AI (or
--                     typed by a user), held as pending_review for the gig /
--                     showcase they were suggested for until a reviewer approves
--                     them (added to the sf_* tables and tagged) or rejects them
--                     (status rejected, deleted_at set; the row is kept).
-- user_notifications  messages for a user, e.g. that their pending term was
--                     rejected. YOUR app shows them (G10) and sets read_at.
--
-- Written by taxonomy-service; read by it and by your review page (through the
-- service's API). A pending term is never in the sf_* tables or a gig's /
-- showcase's tags, so the matcher only ever sees approved terms.
--
-- taxonomy-service also reads `gigs` and `showcases` by `id`, and their `user_id`
-- (the owner to notify of a rejection). If you use the adapter views
-- (../README.md, Step 1), give both views a `user_id` column.
--
-- No foreign keys to your users, gigs or showcases: the history outlives them,
-- and a caller-supplied user id must never make a rejection fail. This variant
-- is for user, gig and showcase ids that are BIGINT UNSIGNED.

CREATE TABLE `taxonomy_proposals` (
  `id`                  int(11) unsigned NOT NULL AUTO_INCREMENT,
  `proposed_name`       varchar(255) NOT NULL,
  `kind`                varchar(20) NOT NULL,                       -- specialisation | skill
  `category_id`         int(11) unsigned DEFAULT NULL,              -- a specialisation's category, or a skill's specialisation's
  `specialisation_id`   int(11) unsigned DEFAULT NULL,              -- a skill's specialisation
  `status`              varchar(20) NOT NULL DEFAULT 'pending_review',  -- pending_review | approved | rejected
  `submitted_by_source` varchar(20) NOT NULL,                       -- ai_suggestion | hirer | provider
  `submitted_by_user`   bigint(20) unsigned DEFAULT NULL,
  `confidence`          decimal(4,3) DEFAULT NULL,                  -- the AI's, 0-1
  `model`               varchar(100) DEFAULT NULL,                  -- the OpenAI model that suggested it
  `owner_type`          varchar(20) DEFAULT NULL,                   -- gig | showcase it was suggested for
  `owner_id`            bigint(20) unsigned DEFAULT NULL,
  `auto_flag`           varchar(20) NOT NULL DEFAULT 'none',        -- none | exact | fuzzy | synonym
  `closest_match_table` varchar(50) DEFAULT NULL,                   -- sf_skills | sf_specialisations | taxonomy_proposals
  `closest_match_id`    int(11) unsigned DEFAULT NULL,
  `closest_match_name`  varchar(255) DEFAULT NULL,
  `similarity_score`    tinyint(3) unsigned DEFAULT NULL,           -- 0-100, for exact / fuzzy
  `approved_item_id`    int(11) unsigned DEFAULT NULL,              -- the sf_skills / sf_specialisations row it became
  `admin_user_id`       bigint(20) unsigned DEFAULT NULL,           -- the reviewer
  `admin_reason`        text DEFAULT NULL,
  `created_at`          datetime DEFAULT NULL,
  `actioned_at`         datetime DEFAULT NULL,
  `deleted_at`          datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `taxonomy_proposals_status` (`status`),
  KEY `taxonomy_proposals_owner` (`owner_type`, `owner_id`),
  -- Reviewed rows outlive a removed category or specialisation.
  CONSTRAINT `taxonomy_proposals_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `sf_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `taxonomy_proposals_specialisation_id_foreign` FOREIGN KEY (`specialisation_id`) REFERENCES `sf_specialisations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `user_notifications` (
  `id`         int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    bigint(20) unsigned NOT NULL,
  `type`       varchar(50) NOT NULL,    -- e.g. taxonomy_proposal_rejected
  `message`    text NOT NULL,           -- ready to show
  `data`       text DEFAULT NULL,       -- JSON: proposal_id, owner_type, owner_id, name, kind, reason
  `read_at`    datetime DEFAULT NULL,   -- set by your app
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_notifications_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
