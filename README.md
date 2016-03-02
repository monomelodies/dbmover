# dbMover
A PHP-based database versioning tool.

## Installation

### Composer (recommended)
```sh
composer require monomelodies/dbmover
```

### Manual
1. Download or clone the repository;
2. There is an executable `dbmover` in the root.

## Design goals
Web applications often work with SQL databases. Programmers will layout such a
database in a "schema file", which is essentially just SQL statements. The
problem arises when, during the course of development or an application's
lifetime, changes to this schema are required. This involves manually applying
the changes to all developers' test databases, perhaps a staging database _and_
eventually the production database(s).

Doing this manually is tedious and error-prone. dbMover automates this task for
you.

## Usage
In the root of your project, place a `dbmover.json` file. This will contain the
settings for dbMover like connections, databasename(s) and the location of your
schema file(s).

The format is as follows:

```json
{
    "dsn": {
        "user": "yourUserName",
        "password": "something secret",
        "schema": ["path/to/schema/file.sql"]
    }
}
```

The contents of `"dsn"` are a bit driver-specific, but will usually be along
the lines of `"engine:host=host,dbname=name,port=1234"`, with one or more being
optional (defaulting to the engine defaults). This is the exact same string that
PHP's `PDO` constructor expects.

The file names of the schemas must be either relative to the directory you're
running from (recommended, since typically you want to keep those in version
control alongside your project's code) or absolute.

> Best practice: leave the config file out of source control, e.g. by adding it
> to `.gitignore`. The database connection credentials will change seldom (if
> ever) so setting this up should mostly be a one-time manual operation.

After defining the config file, run the executable from that same location:

```sh
vendor/bin/dbmover
```

Assuming your database(s) initially match(es) your schema, you should see one or
more "up to date" messages.

## Adding tables
Just add the new table definition to the schema and re-run.

## Adding columns
Forgot a column in a database? No problem, just add it in your schema and re-run
dbMover.

> Note that new columns will always be appended to the end of the table. Some
> database drivers (like MySQL) support the `BEFORE` keyword, but e.g.
> PostgreSQL doesn't and dbMover is as database-agnostic as possible.

## Altering columns
If the column is of the same "base type" (numeric, textual etc.) and keeps the
same name, dbMover will alter that column for you.

## Dropping columns
Just remove them from the schema and re-run.

## Dropping tables
Just remove them from the schema and re-run.

## Loose `ALTER` statements
Sometimes you need to `ALTER` a table after creation specifically, e.g. when it
has a foreign key referring to a table you need to create later on. For example,
a `blog_posts` table might refer to a `lastcomment`, while `blog_post_comments`
in turn refers to a `blog_id` on `blog_posts`. Here you would first create the
posts table, then the comments table (with its foreign key constraint), and
finally add the constraint to the posts table.

Each `ALTER TABLE` statement is run in isolation by dbMover, so just (re)add the
foreign key. The single statement will fail if the key already exists, but
that's fine - it simply means the table was up to date already.

## More complex schema changes
Some things are hard(er) to automatically determine. For these cases, dbMover
creates some temporary procedures you can call in combination with simple SQL
`IF`/`BEGIN`/`END` blocks.

### Renaming a table
To rename an entire table we must determine if the orginal table exists and the
target table doesn't:

```sql
IF dbm_table_exists('original') AND NOT dbm_table_exists('target')
BEGIN
    RENAME TABLE original TO target;
END;
```

If you wouldn't write it this way, dbMover would simply assume the old table
needed to be dropped and a new one should be created.

### Renaming a column
Similar to renaming a table:

```sql
IF dbm_column_exists('table', 'original') AND NOT dbm_column_exists('table', 'target')
BEGIN
    ALTER TABLE table  ALTER COLUMN original target TYPE AND OTHER STUFF;
END;
```

### Running a query only if the table or column is (not) up to date
Use the `dbm_table_uptodate` and `dbm_column_uptodate` functions:

```sql
IF NOT dbm_table_uptodate('table')
BEGIN
    -- This will be executed if something on `table` needs moving.
END;

IF NOT dbm_column_uptodate('table', 'column')
BEGIN
    -- This will be executed if something on `table.column` needs moving.
END;
```

> Caution: the `_uptodate` functions will return `true` if the specified object
> (table or column) has yet to be created.

### Running a query only if the column matches a type
Use the `dbm_column_type` function. This returns the standard SQL string as
stored in `INFORMATION_SCHEMA.COLUMNS`. The exact format is slightly
vendor-specific, but e.g. for MySQL:

```sql
IF NOT dbm_column_type('table', 'column') = 'bigint(21)'
BEGIN
    -- Do something to table.column
END;
```

## Inserting default data
To prevent duplicate inserts, these should be wrapped in an `IF NOT EXISTS ()`
condition like so:

```sql
IF NOT EXISTS (SELECT 1 FROM mytable WHERE id = 1)
BEGIN
    INSERT INTO mytable (id, value1, value2, valueN)
        VALUES (1, 2, 3, 4);
END;
```

## The order of things
While your schema file should run perfectly when called against an empty
database, if the database already contains objects dbMover will reorder the
statements as best it can. In particular:

1. All statements beginning with `IF` are hoisted.
2. All `ALTER TABLE` statements are also hoisted.
3. The above two are run in isolation.
4. All `CREATE TABLE` statements are hoisted and analysed.
    1. If the table should be created, issue that statement verbatim.
    2. If the table needs updating, issue the required update statements.
5. Run all other statements (`CREATE PROCEDURE`, `CREATE VIEW` etc.) and drop
   the specified object first if it already exists (simple recreation).
6. Re-run step 3. Note that `ALTER TABLE` statements will silently fail, and
   presumably some or all conditions that were `false` will now evaluate to
   `true` and vice versa.
7. Attempt to drop anything that was removed from the schema.

## Transferring data from one table to another
This is sometimes necessary. Use `dbm_table_exists` in combination with
`dbm_table_uptodate` on both the original and the target table. This will ensure
the hoisted `IF` block runs at step 6. from the previous section:

```sql
IF dbm_table_exists('original') AND dbm_table_uptodate('original')
    AND dbm_table_exists('target') AND dbm_table_uptodate('target')
BEGIN
    INSERT INTO target SELECT * FROM original;
END;
```

## Caveats

### Be neat
dbMover assumes well-formed SQL, where keywords are written in ALL CAPS. It
does not specifically validate your SQL, though any errors will cause the
statement to fail and the script to halt (so theoretically they can't do much
harm...). dbMover will tell you what error it got.

By "be neat", we mean write `CREATE TABLE` instead of `create Table` etc.

dbMover also doesn't recognise e.g. MySQL's escaping of reserved words using
backticks. Just don't do that, it's evil.

For hoisting, it is assumed that statements-to-be-hoisted are at the beginning
of lines (i.e., e.g. `/^IF /` in regular expression terms). 

### Test your schema first
Always run dbMover against a test database for an updated schema. Everybody
makes typos, you don't want those to mangle a production database. Preferably
you'd test against a _copy_ of the actual production database.

### Bring down your application during migration
Depending on what you're requesting and how big your dataset is, migrations
might take a few minutes. You don't want users editing any data while the schema
isn't in a stable state yet!

### Backup your database before migration
If you tested against an actual copy and it worked fine this shouldn't be
necessary, but better safe than sorry. You might suffer a power outage during
the migration!

Besides, the simple fact that the script runs correctly doesn't necessarily mean
it did what you intended. Always verify your data after a migration!

