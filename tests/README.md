# Automated checks

Run locally from the project root:

```powershell
php tests/smoke.php
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
```

GitHub Actions runs the syntax check, smoke test, and case-collision check on PHP 8.1, 8.2, and 8.3.
