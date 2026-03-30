# Development Guidelines for Claude

## Code Quality Commands

### Check all linting (CI-like check)
```bash
composer lint
```
This runs: PHPCS + Rector (dry-run) + PHPStan

### Fix all auto-fixable issues
```bash
composer lint:fix
```
This runs: Rector (apply fixes) + PHPCBF (fix code style)

### Individual tools
```bash
composer cs          # PHPCS check
composer cs-fix      # PHPCBF fix
composer phpstan     # PHPStan analyse
composer rector        # Rector dry-run
composer rector:fix    # Rector apply
```

## Testing Commands

```bash
composer test                # All tests
composer test:unit           # Unit tests only
composer test:integration    # Integration tests only
```

## Workflow

1. **Before committing:**
   ```bash
   composer lint:fix  # Fix auto-fixable issues
   composer lint      # Verify all checks pass
   ```

2. **If lint fails:**
   - Fix manualnie błędy których nie da się auto-fix
   - Sprawdź ponownie: `composer lint`

3. **Push only when:**
   - `composer lint` przechodzi bez błędów
   - `composer test` przechodzi

## Common Issues

### PHPCS - Multi-line function declaration
Błąd: "The closing parenthesis and the opening brace... must be on the same line"
Rozwiązanie: `composer lint:fix` lub ręcznie popraw formatowanie

### Rector - Unused parameters
Rector może usunąć parametry z niezaimplementowanych metod. Jeśli metoda będzie implementowana w przyszłości, parametry muszą zostać.

### PHPStan - Property only written
Jeśli właściwość jest tylko zapisywana (nie czytana), PHPStan zgłosi błąd. Użyj `@phpstan-ignore property.onlyWritten` jeśli właściwość będzie używana w przyszłości.
