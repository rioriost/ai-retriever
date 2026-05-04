PLUGIN_SLUG := wp-retriever
PLUGIN_FILE := wp-retriever.php
PLUGIN_VERSION := $(shell awk -F ': *' '/^ \* Version:/ { print $$2; exit }' $(PLUGIN_FILE))
BUILD_DIR := build
DIST_DIR := dist
RELEASE_DIR := $(BUILD_DIR)/$(PLUGIN_SLUG)
ZIP_FILE := $(DIST_DIR)/$(PLUGIN_SLUG)-$(PLUGIN_VERSION).zip
PHP_FILES := wp-retriever.php uninstall.php includes

.PHONY: help version install-tools check composer-validate lint phpcs-security composer-audit compose-config clean package release

help:
	@echo "Targets:"
	@echo "  make check    Run release gate checks"
	@echo "  make release  Run checks and build $(ZIP_FILE)"
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
	docker compose config >/dev/null
	docker compose --profile tools config >/dev/null

check: composer-validate lint phpcs-security composer-audit compose-config

clean:
	rm -rf $(BUILD_DIR) $(DIST_DIR)

package:
	rm -rf $(RELEASE_DIR)
	mkdir -p $(RELEASE_DIR) $(DIST_DIR)
	cp wp-retriever.php $(RELEASE_DIR)/
	cp uninstall.php $(RELEASE_DIR)/
	cp README.md $(RELEASE_DIR)/
	cp LICENSE $(RELEASE_DIR)/
	cp -R includes $(RELEASE_DIR)/
	if [ -d assets ]; then cp -R assets $(RELEASE_DIR)/; fi
	if [ -d languages ]; then cp -R languages $(RELEASE_DIR)/; fi
	rm -f $(ZIP_FILE)
	cd $(BUILD_DIR) && zip -qr ../$(ZIP_FILE) $(PLUGIN_SLUG)
	@echo "Built $(ZIP_FILE)"

release: check clean package
