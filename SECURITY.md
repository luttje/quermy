# Security policy

Quermy is a prototype maintained in spare time. Security reports are welcome and taken seriously, but there is no guaranteed response time.

## Supported versions

Only the latest release receives security fixes. If you are on an older release, update first and check whether the problem is still there.

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. That creates a report only the maintainer can see.

It helps to include:

- what an attacker can do, and what access they need to do it;
- the steps to reproduce it, or a proof of concept;
- the Quermy version, PHP version, web server, and database engine involved;
- whether the instance was running in hosted mode (a `config.php` with `server_connection`) or the default open mode.

You will get a reply once the report has been looked at. When a fix is released, the release notes will credit you unless you ask not to be named.

## What counts as a vulnerability

Quermy is a database client. A person who connects with a database account can run any SQL that account is allowed to run; that is the purpose of the tool, not a flaw in it.

Things that are in scope:

- reaching a database, file, or host that the instance's configuration is meant to rule out (for example getting past the fixed host in hosted mode, or using SQLite while it is disabled);
- one visitor reading or changing another visitor's connection, credentials, or data;
- another website being able to act through a user's Quermy session, or to read data from it;
- running script in a user's browser through content Quermy displays (query results, schema names, AI replies);
- the AI assistant's tools changing data, since they are meant to be read-only;
- reading files from, writing files to, or running code on the server.

Things that are expected behaviour:

- running destructive SQL with an account that has the privileges for it;
- in open mode (no `server_connection` in `config.php`), connecting to any database server the Quermy server can reach. This is documented in the [hosting guide](docs/hosting-publicly.md), along with how to restrict it;
- file access through SQLite when the administrator has set `allow_sqlite`, which the same guide warns about;
- the server operator being able to see credentials that pass through their own instance.

## Running Quermy safely

See the [hosting guide](docs/hosting-publicly.md) for the precautions to take before letting other people use an instance.
