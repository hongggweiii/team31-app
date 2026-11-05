-- SkillsFuture taxonomy (sf_v1): category -> specialisation -> skills.
-- The matcher reads these to build every gig's and showcase's tags, and embeds
-- every sf_skills name once at startup (the skill_soft feature).
--
-- Requires MariaDB 11.7 or later (11.8 recommended; see 03_embeddings.sql).
-- Load the rows with ../seed/ after creating the tables (see ../data-setup.md).

CREATE TABLE `sf_categories` (
  `id`         int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name`       varchar(150) NOT NULL,
  `group_name` varchar(20) DEFAULT NULL, -- the records' "group": Industry / Function / Subject / Others (may be NULL)
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A few specialisation names recur under several categories (e.g. "Project
-- Management") and mean different work in each, so names are unique per category only.
CREATE TABLE `sf_specialisations` (
  `id`          int(11) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(11) unsigned NOT NULL,
  `name`        varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_id_name` (`category_id`, `name`),
  CONSTRAINT `sf_specialisations_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `sf_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each skill name once; sf_specialisation_skills links it to every specialisation it belongs to.
CREATE TABLE `sf_skills` (
  `id`   int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `sf_specialisation_skills` (
  `specialisation_id` int(11) unsigned NOT NULL,
  `skill_id`          int(11) unsigned NOT NULL,
  PRIMARY KEY (`specialisation_id`, `skill_id`),
  KEY `skill_id` (`skill_id`),
  CONSTRAINT `sf_specialisation_skills_skill_id_foreign` FOREIGN KEY (`skill_id`) REFERENCES `sf_skills` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sf_specialisation_skills_specialisation_id_foreign` FOREIGN KEY (`specialisation_id`) REFERENCES `sf_specialisations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
