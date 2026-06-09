-- ===================================================================
-- BASE DE DONNÉES MUCTAT - GESTION DES DOSSIERS D'URBANISME
-- Thiès, Sénégal
-- PostgreSQL / Supabase
-- ===================================================================

-- Créer les types ENUM
CREATE TYPE situation_dossier_enum AS ENUM('EN INSTRUCTION', 'COMPLETE', 'ARRETE', 'REJETE', 'INCOMPLETE', 'QUITTANCE NON PAYEE', 'EN SIGNATURE');
CREATE TYPE role_enum AS ENUM('ADMIN', 'CONSULTANT', 'AGENT');
CREATE TYPE avis_enum AS ENUM('Favorable', 'Defavorable', 'En attente', 'N/A');
CREATE TYPE retrait_enum AS ENUM('OUI', 'NON', '');

-- ===================================================================
-- TABLE 1: UTILISATEURS
-- ===================================================================
CREATE TABLE IF NOT EXISTS users (
  id VARCHAR(50) PRIMARY KEY,
  fullname VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(100) NOT NULL UNIQUE,
  role role_enum NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  is_active BOOLEAN DEFAULT TRUE
);

CREATE INDEX idx_username ON users(username);
CREATE INDEX idx_role ON users(role);

-- ===================================================================
-- TABLE 1B: COMMUNES
-- ===================================================================
CREATE TABLE IF NOT EXISTS communes (
  id SERIAL PRIMARY KEY,
  nom VARCHAR(100) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_commune_nom ON communes(nom);

-- ===================================================================
-- TABLE 1C: LOTISSEMENTS
-- ===================================================================
CREATE TABLE IF NOT EXISTS lotissements (
  id SERIAL PRIMARY KEY,
  nom VARCHAR(100) NOT NULL UNIQUE,
  commune_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (commune_id) REFERENCES communes(id) ON DELETE SET NULL
);

CREATE INDEX idx_lotissement_nom ON lotissements(nom);
CREATE INDEX idx_lotissement_commune ON lotissements(commune_id);

-- ===================================================================
-- TABLE 2: DOSSIERS D'URBANISME
-- ===================================================================
CREATE TABLE IF NOT EXISTS dossiers (
  id SERIAL PRIMARY KEY,
  ordre INT NOT NULL UNIQUE,
  num_dossier VARCHAR(50) NOT NULL UNIQUE,
  date_intro DATE NOT NULL,
  date_transmission DATE,
  date_signature DATE,
  
  -- IDENTIFICATION
  num_parcelle VARCHAR(50),
  commune VARCHAR(100),
  lotissement VARCHAR(100),
  civilite VARCHAR(20),
  requerant VARCHAR(150),
  tel_requerant VARCHAR(20),
  depose_par VARCHAR(20) DEFAULT 'PROPRIETAIRE',
  nom_deposant VARCHAR(150),
  
  -- CARACTÉRISTIQUES DU PROJET
  superficie_parcelle DECIMAL(10, 2),
  superficie_batie DECIMAL(10, 2),
  nb_niveaux VARCHAR(10), -- Changé de INT à VARCHAR pour RDC, R+1, etc.
  usage VARCHAR(50),
  type_demande VARCHAR(20), -- CONSTRUCTION, TRANSFORMATION, SURELEVATION
  usage_actuel VARCHAR(50), -- Pour transformation
  usage_souhaite VARCHAR(50), -- Pour transformation
  niveau_transformation VARCHAR(10), -- Niveau concerné par la transformation
  nb_niveaux_actuel VARCHAR(10), -- Pour surélévation
  nb_niveaux_apres VARCHAR(10), -- Pour surélévation
  
  -- TAXES
  taxe_urbanisme DECIMAL(12, 2),
  taxe_municipale DECIMAL(12, 2),
  autres_taxes DECIMAL(12, 2),
  
  -- SUIVI DOSSIER
  situation_dossier situation_dossier_enum DEFAULT 'EN INSTRUCTION',
  num_arrete VARCHAR(50),
  retrait retrait_enum DEFAULT '',
  agent_id VARCHAR(50),
  
  -- INFORMATIONS ADDITIONNELLES
  ia TEXT,
  
  -- AUDIT
  created_by VARCHAR(50),
  updated_by VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  archived_at TIMESTAMP,
  
  FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_num_dossier ON dossiers(num_dossier);
CREATE INDEX idx_archived_at ON dossiers(archived_at);
CREATE INDEX idx_commune ON dossiers(commune);
CREATE INDEX idx_date_intro ON dossiers(date_intro);
CREATE INDEX idx_situation ON dossiers(situation_dossier);
CREATE INDEX idx_requerant ON dossiers(requerant);
CREATE INDEX idx_agent_id ON dossiers(agent_id);

-- ===================================================================
-- TABLE 3: AVIS DES SERVICES (Normalisation de la table dossiers)
-- ===================================================================
CREATE TABLE IF NOT EXISTS avis_services (
  id SERIAL PRIMARY KEY,
  dossier_id INT NOT NULL,
  service_name VARCHAR(50) NOT NULL,
  avis avis_enum DEFAULT 'N/A',
  date_avis TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  observation TEXT,
  
  FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE,
  UNIQUE(dossier_id, service_name)
);

CREATE INDEX idx_service ON avis_services(service_name);
CREATE INDEX idx_avis ON avis_services(avis);

-- ===================================================================
-- TABLE 4: HISTORIQUE DES MODIFICATIONS
-- ===================================================================
CREATE TABLE IF NOT EXISTS dossier_history (
  id SERIAL PRIMARY KEY,
  dossier_id INT NOT NULL,
  user_id VARCHAR(50),
  action VARCHAR(50),
  old_value TEXT,
  new_value TEXT,
  field_name VARCHAR(100),
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
);

CREATE INDEX idx_dossier_id ON dossier_history(dossier_id);
CREATE INDEX idx_changed_at ON dossier_history(changed_at);

-- ===================================================================
-- TABLE 5: STATISTIQUES JOURNALIÈRES (Cache)
-- ===================================================================
CREATE TABLE IF NOT EXISTS daily_stats (
  id SERIAL PRIMARY KEY,
  stat_date DATE NOT NULL UNIQUE,
  total_dossiers INT DEFAULT 0,
  en_instruction INT DEFAULT 0,
  complete INT DEFAULT 0,
  arrete INT DEFAULT 0,
  rejete INT DEFAULT 0,
  total_taxes DECIMAL(15, 2) DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_date ON daily_stats(stat_date);

-- ===================================================================
-- VUES UTILES
-- ===================================================================

-- Vue: Résumé des dossiers avec avis
CREATE OR REPLACE VIEW v_dossiers_resume AS
SELECT 
  d.id,
  d.ordre,
  d.num_dossier,
  d.date_intro,
  d.commune,
  d.requerant,
  d.usage,
  d.situation_dossier,
  d.taxe_urbanisme + d.taxe_municipale + d.autres_taxes AS total_taxes,
  COUNT(CASE WHEN a.avis = 'Favorable'::avis_enum THEN 1 END) AS avis_favorables,
  COUNT(CASE WHEN a.avis = 'Defavorable'::avis_enum THEN 1 END) AS avis_defavorables
FROM dossiers d
LEFT JOIN avis_services a ON d.id = a.dossier_id
GROUP BY d.id, d.ordre, d.num_dossier, d.date_intro, d.commune, d.requerant, d.usage, d.situation_dossier, d.taxe_urbanisme, d.taxe_municipale, d.autres_taxes;

-- Vue: Statistiques par commune
CREATE OR REPLACE VIEW v_stats_communes AS
SELECT 
  commune,
  COUNT(*) AS nb_dossiers,
  SUM(CASE WHEN situation_dossier = 'EN INSTRUCTION'::situation_dossier_enum THEN 1 ELSE 0 END) AS en_instruction,
  SUM(CASE WHEN situation_dossier = 'COMPLETE'::situation_dossier_enum THEN 1 ELSE 0 END) AS complete,
  SUM(CASE WHEN situation_dossier = 'ARRETE'::situation_dossier_enum THEN 1 ELSE 0 END) AS arrete,
  SUM(CASE WHEN situation_dossier = 'REJETE'::situation_dossier_enum THEN 1 ELSE 0 END) AS rejete,
  ROUND(SUM(taxe_urbanisme + taxe_municipale + autres_taxes)::numeric, 2) AS total_taxes
FROM dossiers
GROUP BY commune
ORDER BY nb_dossiers DESC;

-- Vue: Statistiques par usage
CREATE OR REPLACE VIEW v_stats_usage AS
SELECT 
  usage,
  COUNT(*) AS nb_dossiers,
  ROUND(AVG(superficie_parcelle)::numeric, 2) AS superficie_moyenne,
  ROUND(SUM(taxe_urbanisme + taxe_municipale + autres_taxes)::numeric, 2) AS total_taxes
FROM dossiers
GROUP BY usage
ORDER BY nb_dossiers DESC;

-- Vue: Performance des services
CREATE OR REPLACE VIEW v_stats_services AS
SELECT 
  service_name,
  COUNT(*) AS total_avis,
  COUNT(CASE WHEN avis = 'Favorable'::avis_enum THEN 1 END) AS favorable,
  COUNT(CASE WHEN avis = 'Defavorable'::avis_enum THEN 1 END) AS defavorable,
  COUNT(CASE WHEN avis = 'N/A'::avis_enum THEN 1 END) AS na,
  ROUND(100.0 * COUNT(CASE WHEN avis = 'Favorable'::avis_enum THEN 1 END) / COUNT(*)::numeric, 1) AS taux_favorable
FROM avis_services
GROUP BY service_name
ORDER BY favorable DESC;

-- ===================================================================
-- FIN DU SCRIPT - PostgreSQL
-- ===================================================================
