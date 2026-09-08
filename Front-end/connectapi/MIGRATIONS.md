# Database migrations

Run migrations from the project root with PHP CLI before serving the application:

```powershell
php Front-end/connectapi/migrate.php
```

The migration script is CLI-only. Runtime requests must not create or alter database tables. Run migrations again after applying a release that includes a new migration file, and back up the database before production changes.
