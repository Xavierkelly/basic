#!/usr/bin/env bash
# One-time setup of Basic Payroll in a Cloudways PHP app, run over SSH with Master Credentials:
#   bash <(curl -fsSL https://raw.githubusercontent.com/Xavierkelly/basic/master/payroll/bin/cloudways-setup.sh) <app-folder> <app-url>
# e.g. ... abcdefghij https://phpstack-123-456.cloudwaysapps.com
# Moves the app's default files to ../default_files_backup, clones this repo into public_html,
# writes payroll/config.php (asks for the DB username and password; the password is typed hidden
# and only written to config.php), then runs bin/install.php and bin/check.php.
set -euo pipefail

APP="${1:?usage: cloudways-setup.sh <app-folder> <app-url>}"
URL="${2:?usage: cloudways-setup.sh <app-folder> <app-url>}"
URL="${URL%/}"
REPO="${REPO:-https://github.com/Xavierkelly/basic.git}"
BRANCH="${BRANCH:-master}"
ROOT="$HOME/applications/$APP/public_html"

[ -d "$ROOT" ] || { echo "No such app folder: $ROOT" >&2; exit 1; }
cd "$ROOT"

if [ -d .git ]; then
  echo "Code already deployed here; pulling latest $BRANCH."
  git pull --ff-only origin "$BRANCH"
else
  shopt -s dotglob nullglob
  files=(*)
  if [ ${#files[@]} -gt 0 ]; then
    mkdir -p ../default_files_backup
    mv -- "${files[@]}" ../default_files_backup/
    echo "Moved default files to $(cd .. && pwd)/default_files_backup"
  fi
  git clone --branch "$BRANCH" "$REPO" .
fi

cd payroll
if [ -f config.php ]; then
  echo "config.php already exists; leaving it as is."
else
  cp config.example.php config.php
  chmod 640 config.php
  read -r -p "Database name [$APP]: " DBN
  read -r -p "Database username [$APP]: " DBU
  read -r -s -p "Database password (hidden): " DBP; echo
  CFG_URL="$URL" CFG_NAME="${DBN:-$APP}" CFG_USER="${DBU:-$APP}" CFG_PASS="$DBP" php <<'PHP'
<?php
$f = 'config.php';
$c = strtr(file_get_contents($f), [
    "https://your-app.cloudwaysapps.com" => getenv('CFG_URL'),
    "'host' => '127.0.0.1'" => "'host' => 'localhost'",
    "'name' => 'payroll'" => "'name' => " . var_export(getenv('CFG_NAME'), true),
    "'user' => 'payroll'" => "'user' => " . var_export(getenv('CFG_USER'), true),
    "'pass' => 'change-me'" => "'pass' => " . var_export(getenv('CFG_PASS'), true),
]);
file_put_contents($f, $c);
PHP
  unset DBP
  echo "Wrote $(pwd)/config.php"
fi

php bin/install.php
php bin/check.php || true
echo
echo "Done. Open $URL and click Create an account."
