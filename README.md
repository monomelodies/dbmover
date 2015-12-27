# dbMover

A node package that provides versioned database handling.

## Installation
Install the global `dbmover-cli` command, the local `dbmover` package and one or
more database-specific "drivers" for your project. Drivers handle
vendor-specific RMDBS quirks, so e.g. `npm install dbmover-mysql`.

```bash
npm install -g dbmover-cli
cd /path/to/project
npm install --save dbmover dbmover-DRIVER
```

## Configuration
dbMover looks for a file called `dbmover.json` in the current directory
(typically the root of your project, but sub-configurations are perfectly valid
too). At the root level of this file, each key represents a database
(environment), e.g. `"dev"` or `"prod"`.

> Note that by design, the config file may define multiple databases and is
> intended to be "versionable", i.e. you can add it to your VCS. You will
> specify which environment to use on invocation.

Each database config is itself an object with three keys: "driver", "dsn" and
"schemas".

#### `"driver"`
This should contain a string with the name of a valid dbMover driver module. It
is the (optionally absolute) pathname used to `require` the module. This allows
for flexible extending of dbMover.

Currently only `"dbmover-mysql"` is supported. Support for `"dbmover-pgsql"`
and `"dbmover-sqlite"` is planned.

#### `"dsn"`
Describes how your driver should connect to the database. E.g.:
`"mysql://user:password@host/database"`.

#### `"schemas"`
An array of schema files to use. These can be either relative to the current
location, or absolute (not recommended).

### Keeping it DRY
If your database configs are already stored in a JSON file, you can reference
that in your config for the `"connection"` key instead like so:

```json
{
    "my-environment": {
        "connection": "/path/to/config.json@foo.bar"
    }
}
```

This will instead include `/path/to/config.json` and look for the property
`"bar"` on the `"foo"` key. If the config file uses different keys (it happens)
you can also be more specific:

```json
{
    "my-environment": {
        "connection": {
            "username": "/path/to/config.json@foo.bar.myname",
            "password": "/path/to/config.json@foo.bar.mysecret",
            "database": "/path/to/config.json@foo.bar.mydatastore"
        }
    }
}
```

> The `"host"` key is optional and defaults to `"localhost"`.

## Usage
`cd` to where your `dbmover.json` file is stored, and invoke the global
`dbmover` command with one or more database environment parameters:

```bash
$ cd /path/to/project
$ dbmover migrate database-environment-key [yet-another-key [...]]
```

This will analyse the specified databases _and_ the associated schema files, and
attempt to apply any changes in order. The first argument (`migrate`) is the
command to run dbMover with. We'll look at other commands later.

## Writing schema files
The schema files should contain just your regular definitions, as if you were
starting a new project. E.g.:

```sql
CREATE TABLE foo (
    bar INTEGER NOT NULL
);
```

### Non-persisting database objects
These are essentially anything that is not an actual table. The idea is that
these can at any point be dropped and recreated. Hence, your schema should take
care to create these in a "clean" fashion. E.g. in MySQL:

```sql
DROP VIEW IF EXISTS some_view;
CREATE VIEW some_view AS SELECT * FROM some_table;
```

dbMover is only interested in `CREATE TABLE` statements.

If during your application's life cycle you need to add a column to a table,
simply change the `CREATE TABLE` statement in your schema to reflect the new
situation and run dbMover on it.

## Annotating history
dbMover is _smart_, but not _clairvoyant_. Sometimes you'll need to _rename_ an
existing column, and presumably don't want to lose existing data (especially on
a live server...). For this purpose dbMover requires objects to be _annotated_.

An annotation is simply an SQL comment with an `"@something some_params"`
syntax. For instance, to specify a table has been renamed we would write:

```sql
-- @was foo
CREATE TABLE bar (
    -- columns...
);
```

This is rewritten to `ALTER TABLE foo RENAME TO bar` if `foo` exists. If no such
table exists, `bar` is created as specified. Should at any point `bar` need to
be renamed to `baz`, your schema would have a _double annotation_:

```sql
-- @was foo
-- @was bar
CREATE TABLE baz (
    -- columns...
);
```

The idea is that the migration can now be applied to a database in any of the
older states.

> If you're working on a small team and are 100% sure no one is still using an
> older version, it's of course perfectly fine to clean up the annotations at
> some point in time. But since comments aren't harmful either, you might as
> well keep them for posterity.

### Renaming columns
Annotations also work on the column level:

```sql
CREATE TABLE foo (
    -- @was foo
    bar VARCHAR(255) NOT NULL
);
```

This will check if a `foo` column exists, and rename it to `bar`. Otherwise, it
will simply add the column `bar`.

If a column is renamed _and_ changed, the rename will run first and the
transformation afterwards, unless the RMDBS supports a single-statement
alteration (like MySQL).

### Conflict resolutions
What happens, you might ask, in the following situation?

```sql
-- @was bar
CREATE TABLE foo (
);

-- @was foo
CREATE TABLE bar (
);
```

Schema files are processed sequentially (like RMDBSs do) so the above first
tries to create or rename `foo`, then create or rename `bar`. So on subsequent
runs, the above example would _always_ trigger. It's up to you to make sure
these kind of deadlocks don't exist in your schema file; they wouldn't make
much sense anyway usually.

## Conditionals
dbMover also support annotations with conditionality: the `@if` and `@ifnot`
annotations. The "argument" to these is simply an SQL string to be used as a
test:

```sql
-- This would empty `some_table` if it contains data:
-- @if SELECT 1 FROM some_table
DELETE FROM some_table;
-- @endif
```

Note that conditionals are checked on each run, so the above example would
consistently empty `some_table` for every migration.

```sql
-- This will insert into `some_table` conditionally:
-- @ifnot SELECT 1 FROM some_table WHERE foo = 'bar'
INSERT INTO some_table (foo) VALUES ('bar');
-- @endif
```

For schemas containing lots of complex renames, or when referencing other schema
files (e.g. conditionally altering a table defined externally) you can utilise
something like `INFORMATION_SCHEMA` to check if the operation has already
performed.

## Testing your schema
Transaction support isn't reliable in RDMBSs (e.g. MySQL implicitly commits on
structure altering commands). Hence, dbMover employs a different strategy for
schema validation.

For each environment key, you can also add a key with the same name but
postfixed with the string `"$TEST"`. E.g.:

```json
{
    "foo": { ... settings for foo ... },
    "foo$TEST": { ... settings for footest ... }
}
```


Use the `test` command to run your entire schema against an empty database. It
will contain `0` if everything is okay, or an error code if not (in which case
you should fix your schema first :)).

## Inspecting operations
Use the `dryrun` command to simply output to the console exactly what dbMover is
planning on doing to your database.

