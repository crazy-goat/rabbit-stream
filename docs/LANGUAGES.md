# Available Languages

This document lists all available translations of the RabbitStream documentation and provides guidelines for contributing new translations.

## Currently Available Languages

| Language | Code | Status | Maintainer |
|----------|------|--------|------------|
| English | `en` | Complete (stubs) | CrazyGoat Team |

## Planned Translations

The following languages are planned for future translation:

- Polish (`pl`)
- Spanish (`es`)
- French (`fr`)
- German (`de`)

## File Naming Conventions

RabbitStream documentation uses a hybrid approach for internationalization:

### Root Index Files

Root-level index files use the file-suffix convention:

- `index.md` - Default language (English)
- `index.pl.md` - Polish translation
- `index.es.md` - Spanish translation
- `index.fr.md` - French translation

### Content Directories

Language-specific content is organized in subdirectories:

```
docs/
├── index.md              # English (default)
├── index.pl.md           # Polish translation
├── LANGUAGES.md          # This file
├── en/                   # English content
│   ├── getting-started/
│   ├── guide/
│   └── ...
└── pl/                   # Polish content (future)
    ├── getting-started/
    ├── guide/
    └── ...
```

## Contributing Translations

We welcome contributions for new language translations! Here's how to add a new language:

### 1. Create an Issue

Before starting work, create an issue on GitHub to:
- Announce your intention to translate
- Coordinate with other contributors
- Get guidance from maintainers

### 2. Fork and Branch

```bash
git checkout -b docs/translation-{language-code}
```

### 3. Create Directory Structure

Create the language directory and copy the English structure:

```bash
mkdir -p docs/{language-code}/getting-started
cp docs/en/getting-started/*.md docs/{language-code}/getting-started/
# Repeat for all subdirectories
```

### 4. Translate Content

- Translate all `.md` files in your language directory
- Keep the same file names (only content changes)
- Maintain the same structure and headers
- Update code examples if necessary for your language community

### 5. Create Root Index File

Create `docs/index.{language-code}.md` as a translation of `docs/index.md`.

### 6. Update LANGUAGES.md

Add your language to the "Currently Available Languages" table.

### 7. Submit Pull Request

- Include "[i18n]" in the PR title
- Reference the issue you created
- Note the percentage of content translated

## Translation Guidelines

### Technical Terms

- Keep protocol-specific terms in English (e.g., "stream", "offset", "publisher", "consumer")
- Translate high-level concepts (e.g., "Getting Started" → "Primeros Pasos" in Spanish)
- Keep code examples in English (class names, method names, variables)

### Code Examples

Code examples should generally remain in English since:
- PHP code uses English keywords
- Class and method names are in English
- This ensures code can be copied and used directly

However, you may add comments in your language to explain the code.

### Links

- Update internal links to point to your language directory
- Keep external links as-is (RabbitMQ docs, PHP.net, etc.)

### Maintaining Translations

Translations should be kept in sync with the English documentation:

1. Watch the repository for changes
2. When English docs are updated, update your translation
3. Mark pages with a "Last Updated" date
4. Note if a translation is outdated

## Translation Status

To check the status of a translation:

```bash
# Count total English files
find docs/en -name "*.md" | wc -l

# Count translated files
find docs/{language-code} -name "*.md" | wc -l
```

## Questions?

If you have questions about translations:

1. Check existing translations for examples
2. Ask in the GitHub issue for your translation
3. Contact the maintainers

Thank you for helping make RabbitStream documentation accessible to more developers!
