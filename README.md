# NucleoBase — Functional Nucleotide Database

A PHP + MySQL web app for storing, browsing, and managing nucleotide
(DNA/RNA) sequence records in FASTA format, styled with Tailwind CSS.

## Features

- **Browse & search** — filter by organism, sequence type (DNA/RNA), or free-text
  search across accession number, gene name, organism, and description. Paginated.
- **View** — record detail page with a color-coded sequence viewer (A/T·U/G/C
  each get a distinct color, genome-browser style), sequence length, GC%, and
  uploader info.
- **Upload** (login required) — upload a `.fasta`/`.fa`/`.fna`/`.txt` file or paste
  FASTA text directly. Supports multi-record FASTA files (one upload can import
  several sequences at once). Validates the sequence only contains IUPAC
  nucleotide codes and rejects duplicate accession numbers.
- **Edit / Delete** (login required) — update a record's metadata and sequence
  (length/GC%/type are recalculated automatically), or delete it with a
  confirmation step.
- **Download** — any record can be downloaded as a properly line-wrapped
  `.fasta` file, open to all visitors.
- **Dashboard** (login required) — see your own uploaded records and your
  recent activity.
- **Audit trail** — every create/update/delete/download is recorded in
  `activity_log` with the acting user, for database accountability.

**Design note / assumption:** per the spec, login is required to *upload*,
*update*, and *delete* records; *viewing* and *downloading* are open to all
visitors. There is no self-service registration page — accounts are created
directly in the `users` table (see below) — so only trusted users can be
given the ability to modify data.

## Requirements

- PHP 8.1+ with the `pdo_mysql` extension
- MySQL 5.7+ / MariaDB 10.4+
- A web server (Apache/Nginx) or PHP's built-in server for local dev

## Setup

1. **Create the database**
   ```bash
   mysql -u root -p < database/schema.sql
   ```
   This creates the `nucleotide_db` database, all tables, and two seed
   records plus a demo account (`demo` / `demo1234`).

2. **Configure the connection**
   Edit `config/database.php` and set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   to match your MySQL setup.

3. **Create additional user accounts** (since there's no signup form):
   ```sql
   INSERT INTO users (username, email, password_hash, full_name)
   VALUES ('yourname', 'you@example.com',
           -- generate this with: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
           '$2y$10$...',
           'Your Name');
   ```

4. **Run it**
   - With PHP's built-in server (quick local testing):
     ```bash
     php -S localhost:8000
     ```
     then visit `http://localhost:8000`.
   - Or point Apache/Nginx's document root at this folder.

## Project structure

```
config/database.php     PDO connection
includes/functions.php  Auth helpers, FASTA parser, GC%/type calculators,
                         colorized sequence renderer, activity logger
includes/header.php     Shared layout, nav, Tailwind config
includes/footer.php     Shared layout close
database/schema.sql     Full schema + seed data
index.php               Browse / search (public)
view.php                Record detail (public)
download.php            FASTA download (public)
login.php / logout.php  Auth
upload.php              Upload/import FASTA (login required)
edit.php                Edit record (login required)
delete.php              Delete record with confirmation (login required)
dashboard.php           My records + my activity (login required)
```

## Database schema

- **users** — accounts (`id`, `username`, `email`, `password_hash`, `full_name`)
- **nucleotide_records** — one row per sequence (`accession_number` unique,
  `organism`, `gene_name`, `sequence_type` ENUM DNA/RNA, `description`,
  `sequence` LONGTEXT, `sequence_length`, `gc_content`, `uploaded_by` FK,
  timestamps). Indexed on organism/gene/type, plus a FULLTEXT index for search.
- **activity_log** — audit trail (`user_id`, `record_id`, `action` ENUM
  CREATE/UPDATE/DELETE/DOWNLOAD, `details`, `created_at`)

## Security notes

- Passwords are hashed with `password_hash()` / verified with `password_verify()`.
- All SQL uses PDO prepared statements (no string-concatenated queries).
- All output is escaped with `htmlspecialchars()` via the `h()` helper.
- Session ID is regenerated on login to prevent session fixation.
- Destructive actions (delete) require a POST request with a confirmation
  screen, not a plain GET link.
- Uploaded FASTA content is validated against the IUPAC nucleotide alphabet
  before being stored.
# ACGT-Nucleobase
# ACGT-Nucleobase
