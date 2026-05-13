.PHONY: help test lint clean l10n-js
help:
	@echo "Targets:"
	@echo "  test    - Run PHPUnit unit + integration tests"
	@echo "  lint    - Run lint checks (php -l)"
	@echo "  clean   - Remove vendor/, build artefacts"
	@echo "  l10n-js - Regenerate l10n/*.js from l10n/*.json (run after editing JSON)"

l10n-js:
	@node -e " \
		const fs = require('fs'); \
		const locales = fs.readdirSync('l10n').filter(f => f.endsWith('.json')).map(f => f.replace('.json', '')); \
		for (const locale of locales) { \
			const json = JSON.parse(fs.readFileSync('l10n/' + locale + '.json', 'utf8')); \
			const trans = json.translations || {}; \
			const plural = json.pluralForm || 'nplurals=2; plural=(n != 1);'; \
			const content = 'OC.L10N.register(\n\t\"mobilitycheck\",\n\t' + JSON.stringify(trans) + ',\n\t' + JSON.stringify(plural) + '\n);\n'; \
			fs.writeFileSync('l10n/' + locale + '.js', content); \
			console.log('Generated l10n/' + locale + '.js'); \
		} \
	"

test:
	./vendor/bin/phpunit

lint:
	@find lib tests -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

clean:
	rm -rf vendor/ build/
