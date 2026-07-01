PLUGIN_SLUG := ai-retriever
PLUGIN_FILE := ai-retriever.php
PLUGIN_VERSION := $(shell awk -F ': *' '/^ \* Version:/ { print $$2; exit }' $(PLUGIN_FILE))
BUILD_DIR := build
DIST_DIR := dist
RELEASE_DIR := $(BUILD_DIR)/$(PLUGIN_SLUG)
ZIP_FILE := $(DIST_DIR)/$(PLUGIN_SLUG)-$(PLUGIN_VERSION).zip
PHP_FILES := ai-retriever.php uninstall.php includes
POT_FILE := languages/$(PLUGIN_SLUG).pot

.PHONY: help version install-tools static-check check composer-validate lint phpcs-security composer-audit compose-config plugin-check i18n-pot i18n-pot-check release-audit package-audit clean package release

help:
	@echo "Targets:"
	@echo "  make check    Run WordPress.org release gate checks"
	@echo "  make release  Run release gates and build $(ZIP_FILE)"
	@echo "  version       $(PLUGIN_VERSION)"
	@echo "  make clean    Remove build artifacts"

version:
	@echo $(PLUGIN_VERSION)

install-tools:
	composer install --no-interaction --no-progress

composer-validate: install-tools
	composer validate --strict --no-interaction

lint:
	find . -path ./vendor -prune -o -path ./$(BUILD_DIR) -prune -o -path ./$(DIST_DIR) -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

phpcs-security: install-tools
	vendor/bin/phpcs --standard=phpcs-security.xml.dist --report=summary

composer-audit: install-tools
	composer audit --no-interaction

compose-config:
	if command -v docker >/dev/null 2>&1; then docker compose config >/dev/null && docker compose --profile tools config >/dev/null; else echo "docker not found; skipping Compose config validation"; fi

plugin-check:
	PLUGIN_ZIP="$(ZIP_FILE)" sh scripts/run-plugin-check.sh

i18n-pot:
	php scripts/build-pot.php $(POT_FILE)

i18n-pot-check:
	tmp="$$(mktemp)"; php scripts/build-pot.php "$$tmp"; diff -u $(POT_FILE) "$$tmp"; rm -f "$$tmp"

release-audit:
	grep -q '^ \* Plugin Name:       AI Retriever$$' $(PLUGIN_FILE)
	grep -q '^Stable tag: $(PLUGIN_VERSION)$$' readme.txt
	test -f $(POT_FILE)
	test -f readme.txt

static-check: composer-validate lint phpcs-security composer-audit compose-config i18n-pot-check release-audit

check: static-check clean package package-audit plugin-check

clean:
	rm -rf $(BUILD_DIR) $(DIST_DIR)

package:
	rm -rf $(RELEASE_DIR)
	mkdir -p $(RELEASE_DIR) $(DIST_DIR)
	cp $(PLUGIN_FILE) $(RELEASE_DIR)/
	cp uninstall.php $(RELEASE_DIR)/
	cp readme.txt $(RELEASE_DIR)/
	cp README.md $(RELEASE_DIR)/
	cp LICENSE $(RELEASE_DIR)/
	cp -R includes $(RELEASE_DIR)/
	if [ -d assets ]; then cp -R assets $(RELEASE_DIR)/; fi
	if [ -d languages ]; then cp -R languages $(RELEASE_DIR)/; fi
	rm -f $(ZIP_FILE)
	cd $(BUILD_DIR) && zip -qr ../$(ZIP_FILE) $(PLUGIN_SLUG)
	@echo "Built $(ZIP_FILE)"

package-audit:
	test -f $(ZIP_FILE)
	unzip -l $(ZIP_FILE) | grep -q '$(PLUGIN_SLUG)/readme.txt'
	unzip -l $(ZIP_FILE) | grep -q '$(PLUGIN_SLUG)/$(POT_FILE)'
	! unzip -l $(ZIP_FILE) | grep -E '/(tmp|vendor|build|dist|\\.git)/' >/dev/null

release: check
