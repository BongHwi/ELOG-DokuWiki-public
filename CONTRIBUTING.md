# Contributing

Thank you for your interest in contributing to ELOG-DokuWiki.

## Development Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/BongHwi/ELOG-DokuWiki.git
   cd ELOG-DokuWiki
   ```

2. Run the setup script:
   ```bash
   ./setup.sh
   ```

3. Start with Docker:
   ```bash
   ./start.sh --build
   ```

4. The template files at `lib/tpl/elog/` are mounted as a Docker volume, so changes are reflected immediately on browser refresh.

## Project Structure

The core logic is in the DokuWiki template:

- `lib/tpl/elog/elog_functions.php` — Business logic (action handling, forms, listing)
- `lib/tpl/elog/main.php` — HTML layout
- `lib/tpl/elog/css/` — Stylesheets

## Making Changes

1. Create a branch for your changes
2. Make your edits
3. Test by refreshing the browser (template changes are live via volume mount)
4. Verify the change works for both admin and regular user roles
5. Submit a pull request with a description of the change

## Reporting Issues

Please use [GitHub Issues](https://github.com/BongHwi/ELOG-DokuWiki/issues) to report bugs or request features. Include:

- Steps to reproduce (for bugs)
- Expected vs actual behavior
- Browser and OS information
- Docker version (`docker --version`)

## Code Style

- PHP: Follow existing patterns in the codebase
- CSS: Use the CSS custom properties defined in `conf/userstyle.css`
- Shell scripts: Use `set -euo pipefail` and quote variables

## License

By contributing, you agree that your contributions will be licensed under the GPL-2.0 license.
