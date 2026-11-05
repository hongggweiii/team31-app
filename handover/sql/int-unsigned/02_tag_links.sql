-- The tags each gig and showcase carries: its specialisations, and per
-- specialisation its skills. Categories are not stored: they are the parents of
-- the specialisations, so every chosen category must have at least one
-- specialisation (enforce this in your form).
--
-- Limits (enforce in your form; the database does not): up to 2 categories,
-- 1-3 specialisations per category, up to 8 skills per specialisation.
--
-- The composite foreign keys keep the data consistent: a skill row can only
-- exist under a specialisation the gig / showcase also has, and only if that
-- specialisation contains the skill in sf_specialisation_skills.
--
-- `showcases` and `gigs` are your own tables (see README.md, "Your tables").
-- If they are named differently, change the REFERENCES clauses below.
-- This variant is for tables whose `id` is INT UNSIGNED (see ../README.md).

CREATE TABLE `showcase_specialisations` (
  `showcase_id`       int(11) unsigned NOT NULL,
  `specialisation_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`showcase_id`, `specialisation_id`),
  KEY `specialisation_id` (`specialisation_id`),
  CONSTRAINT `showcase_specialisations_showcase_id_foreign` FOREIGN KEY (`showcase_id`) REFERENCES `showcases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `showcase_specialisations_specialisation_id_foreign` FOREIGN KEY (`specialisation_id`) REFERENCES `sf_specialisations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `showcase_skills` (
  `showcase_id`       int(11) unsigned NOT NULL,
  `specialisation_id` int(11) unsigned NOT NULL,
  `skill_id`          int(11) unsigned NOT NULL,
  PRIMARY KEY (`showcase_id`, `specialisation_id`, `skill_id`),
  KEY `specialisation_id_skill_id` (`specialisation_id`, `skill_id`),
  CONSTRAINT `showcase_skills_showcase_id_specialisation_id_foreign` FOREIGN KEY (`showcase_id`, `specialisation_id`) REFERENCES `showcase_specialisations` (`showcase_id`, `specialisation_id`) ON DELETE CASCADE,
  CONSTRAINT `showcase_skills_specialisation_id_skill_id_foreign` FOREIGN KEY (`specialisation_id`, `skill_id`) REFERENCES `sf_specialisation_skills` (`specialisation_id`, `skill_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `gig_specialisations` (
  `gig_id`            int(11) unsigned NOT NULL,
  `specialisation_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`gig_id`, `specialisation_id`),
  KEY `specialisation_id` (`specialisation_id`),
  CONSTRAINT `gig_specialisations_gig_id_foreign` FOREIGN KEY (`gig_id`) REFERENCES `gigs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gig_specialisations_specialisation_id_foreign` FOREIGN KEY (`specialisation_id`) REFERENCES `sf_specialisations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `gig_skills` (
  `gig_id`            int(11) unsigned NOT NULL,
  `specialisation_id` int(11) unsigned NOT NULL,
  `skill_id`          int(11) unsigned NOT NULL,
  PRIMARY KEY (`gig_id`, `specialisation_id`, `skill_id`),
  KEY `specialisation_id_skill_id` (`specialisation_id`, `skill_id`),
  CONSTRAINT `gig_skills_gig_id_specialisation_id_foreign` FOREIGN KEY (`gig_id`, `specialisation_id`) REFERENCES `gig_specialisations` (`gig_id`, `specialisation_id`) ON DELETE CASCADE,
  CONSTRAINT `gig_skills_specialisation_id_skill_id_foreign` FOREIGN KEY (`specialisation_id`, `skill_id`) REFERENCES `sf_specialisation_skills` (`specialisation_id`, `skill_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
