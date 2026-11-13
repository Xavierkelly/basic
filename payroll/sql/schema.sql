-- Payroll app schema (MySQL 8 / MariaDB 10.6+). Every business table is scoped by company_id.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Chicago',
  period_start_weekday TINYINT NOT NULL DEFAULT 1,          -- 1 = Monday (ISO)
  pay_date_offset_days SMALLINT NOT NULL DEFAULT 5,         -- pay date = period end + N days
  periods_per_year SMALLINT NOT NULL DEFAULT 52,
  time_entry_mode ENUM('clock','weekly_total') NOT NULL DEFAULT 'weekly_total',
  overtime_enabled TINYINT(1) NOT NULL DEFAULT 0,
  overtime_threshold_hours DECIMAL(6,2) NOT NULL DEFAULT 40.00,
  overtime_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.50,
  rate_1099_withholding DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
  ytd_start_date DATE NULL,                                 -- paid checks on/after this date count toward YTD wages
  require_location TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL DEFAULT '',
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  worker_type ENUM('W2','1099') NOT NULL DEFAULT 'W2',
  default_branch_id INT UNSIGNED NULL,
  default_department_id INT UNSIGNED NULL,
  pay_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  weekly_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payout_method VARCHAR(40) NULL,
  payout_handle VARCHAR(120) NULL,
  time_entry_mode ENUM('clock','weekly_total') NULL,        -- NULL = company default
  overtime_enabled TINYINT(1) NULL,                         -- NULL = company default
  active TINYINT(1) NOT NULL DEFAULT 1,
  hired_on DATE NULL,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emp_company (company_id, active),
  CONSTRAINT fk_emp_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','admin','manager','employee') NOT NULL DEFAULT 'employee',
  employee_id INT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_email (email),
  KEY idx_user_company (company_id),
  CONSTRAINT fk_user_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscriptions (
  company_id INT UNSIGNED PRIMARY KEY,
  plan VARCHAR(40) NOT NULL DEFAULT 'basic',
  status ENUM('trialing','active','past_due','canceled') NOT NULL DEFAULT 'trialing',
  trial_ends_at DATETIME NULL,
  current_period_end DATETIME NULL,
  square_customer_id VARCHAR(64) NULL,
  square_subscription_id VARCHAR(64) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sub_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Branch and department are plain tags.
CREATE TABLE IF NOT EXISTS tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  kind ENUM('branch','department') NOT NULL,
  name VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_tag (company_id, kind, name),
  CONSTRAINT fk_tag_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_tax_profiles (
  employee_id INT UNSIGNED PRIMARY KEY,
  w4_entered TINYINT(1) NOT NULL DEFAULT 0,
  filing_status ENUM('Single','Married filing jointly','Head of household') NOT NULL DEFAULT 'Single',
  multiple_jobs TINYINT(1) NOT NULL DEFAULT 0,
  other_income DECIMAL(12,2) NOT NULL DEFAULT 0,      -- W-4 4(a), annual
  deductions DECIMAL(12,2) NOT NULL DEFAULT 0,        -- W-4 4(b), annual
  extra_withholding DECIMAL(10,2) NOT NULL DEFAULT 0, -- W-4 4(c), per check
  federal_exempt TINYINT(1) NOT NULL DEFAULT 0,
  children_under_17 SMALLINT NULL,
  other_dependents SMALLINT NULL,
  prior_annual_credit DECIMAL(10,2) NOT NULL DEFAULT 0,
  credit_override DECIMAL(10,2) NULL,
  tax_state VARCHAR(4) NOT NULL DEFAULT 'TX',
  il_allowances_1 SMALLINT NOT NULL DEFAULT 0,
  il_allowances_2 SMALLINT NOT NULL DEFAULT 0,
  il_extra DECIMAL(10,2) NOT NULL DEFAULT 0,
  il_exempt TINYINT(1) NOT NULL DEFAULT 0,
  ytd_prior_year SMALLINT NULL,                       -- year the prior-YTD figures belong to
  ytd_prior_ss_wages DECIMAL(12,2) NOT NULL DEFAULT 0,
  ytd_prior_medicare_wages DECIMAL(12,2) NOT NULL DEFAULT 0,
  ytd_verified TINYINT(1) NOT NULL DEFAULT 0,
  prior_gross_before_app DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_tax_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS time_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  clock_in DATETIME NOT NULL,          -- stored in UTC
  clock_out DATETIME NULL,             -- stored in UTC
  in_lat DECIMAL(9,6) NULL, in_lng DECIMAL(9,6) NULL, in_accuracy_m INT NULL,
  out_lat DECIMAL(9,6) NULL, out_lng DECIMAL(9,6) NULL, out_accuracy_m INT NULL,
  location_missing TINYINT(1) NOT NULL DEFAULT 0,
  source ENUM('clock','manual') NOT NULL DEFAULT 'clock',
  note VARCHAR(255) NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  KEY idx_te_emp (company_id, employee_id, clock_in),
  CONSTRAINT fk_te_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_te_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_periods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  pay_date DATE NOT NULL,
  label VARCHAR(60) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  imported TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_period (company_id, start_date),
  CONSTRAINT fk_pp_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS paychecks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  pay_period_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  worker_type ENUM('W2','1099') NOT NULL,
  status ENUM('pending','paid','did_not_work') NOT NULL DEFAULT 'pending',
  hours DECIMAL(8,2) NOT NULL DEFAULT 0,
  ot_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
  rate DECIMAL(10,2) NOT NULL DEFAULT 0,
  hourly_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  ot_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
  salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  commissions DECIMAL(12,2) NOT NULL DEFAULT 0,
  gross DECIMAL(12,2) NOT NULL DEFAULT 0,
  pretax_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
  federal_tax DECIMAL(10,2) NOT NULL DEFAULT 0,
  ss_tax DECIMAL(10,2) NOT NULL DEFAULT 0,
  medicare_tax DECIMAL(10,2) NOT NULL DEFAULT 0,
  addl_medicare_tax DECIMAL(10,2) NOT NULL DEFAULT 0,
  state_tax DECIMAL(10,2) NOT NULL DEFAULT 0,
  taxes DECIMAL(10,2) NOT NULL DEFAULT 0,
  deductions DECIMAL(12,2) NOT NULL DEFAULT 0,        -- after-tax
  net DECIMAL(12,2) NOT NULL DEFAULT 0,
  sent DECIMAL(12,2) NOT NULL DEFAULT 0,
  ss_wages DECIMAL(12,2) NOT NULL DEFAULT 0,
  medicare_wages DECIMAL(12,2) NOT NULL DEFAULT 0,
  payout_method VARCHAR(40) NULL,
  calc_status VARCHAR(120) NULL,
  manual_taxes TINYINT(1) NOT NULL DEFAULT 0,         -- 1 = taxes typed in (imported history), not recalculated
  locked TINYINT(1) NOT NULL DEFAULT 0,
  paid_at DATETIME NULL,
  notes VARCHAR(500) NULL,
  KEY idx_pc_period (company_id, pay_period_id),
  KEY idx_pc_emp (company_id, employee_id),
  CONSTRAINT fk_pc_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_period FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NULL,
  detail JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_company (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Global tax reference data, by year.
CREATE TABLE IF NOT EXISTS tax_brackets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tax_year SMALLINT NOT NULL,
  schedule VARCHAR(60) NOT NULL,       -- e.g. 'Single|Standard'
  lower_bound DECIMAL(12,2) NOT NULL,
  base_tax DECIMAL(12,2) NOT NULL,
  rate DECIMAL(6,4) NOT NULL,
  KEY idx_br (tax_year, schedule, lower_bound)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tax_constants (
  tax_year SMALLINT NOT NULL,
  name VARCHAR(60) NOT NULL,
  value DECIMAL(14,4) NOT NULL,
  PRIMARY KEY (tax_year, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
