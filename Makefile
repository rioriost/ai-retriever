PLUGIN_SLUG := ritriever
PLUGIN_FILE := ritriever.php
PLUGIN_VERSION := $(shell awk -F ': *' '/^ \* Version:/ { print $$2; exit }' $(PLUGIN_FILE))
BUILD_DIR := build
DIST_DIR := dist
RELEASE_DIR := $(BUILD_DIR)/$(PLUGIN_SLUG)
ZIP_FILE := $(DIST_DIR)/$(PLUGIN_SLUG)-$(PLUGIN_VERSION).zip
PHP_FILES := ritriever.php uninstall.php includes
POT_FILE := languages/$(PLUGIN_SLUG).pot

.PHONY: help version install-tools static-check check composer-validate lint phpcs-security composer-audit compose-config plugin-check apple-container-up apple-container-down apple-container-reset i18n-pot i18n-pot-check release-audit review-audit package-audit clean package release

help:
	@echo "Targets:"
	@echo "  make check    Run WordPress.org release gate checks"
	@echo "  make release  Run release gates and build $(ZIP_FILE)"
	@echo "  make apple-container-up    Start local WordPress with Apple container"
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
	APPLE_CONTAINER_AUTO_START=$${APPLE_CONTAINER_AUTO_START:-1} PLUGIN_ZIP="$(ZIP_FILE)" sh scripts/run-plugin-check.sh

apple-container-up:
	sh scripts/apple-container-wordpress.sh up

apple-container-down:
	sh scripts/apple-container-wordpress.sh down

apple-container-reset:
	sh scripts/apple-container-wordpress.sh reset

i18n-pot:
	php scripts/build-pot.php $(POT_FILE)

i18n-pot-check:
	tmp="$$(mktemp)"; php scripts/build-pot.php "$$tmp"; diff -u $(POT_FILE) "$$tmp"; rm -f "$$tmp"

release-audit:
	grep -q '^ \* Plugin Name:       RiTriever$$' $(PLUGIN_FILE)
	grep -q '^ \* Text Domain:       $(PLUGIN_SLUG)$$' $(PLUGIN_FILE)
	grep -q '^=== RiTriever ===$$' readme.txt
	grep -q '^Stable tag: $(PLUGIN_VERSION)$$' readme.txt
	test -f $(POT_FILE)
	test -f readme.txt
	grep -q '^== External services ==$$' readme.txt

review-audit:
	! grep -RInE 'WPRetriever|WP_RETRIEVER|wp_retriever|wp-retriever|wpRetriever|AI Retriever|ai-retriever' -- $(PLUGIN_FILE) uninstall.php includes assets languages readme.txt README.md composer.json phpcs-security.xml.dist scripts
	! grep -RInE 'wp_ajax_wp_|admin_post_wp_|wp_enqueue_script\("wp-' -- $(PLUGIN_FILE) includes assets
	! grep -RInE '<script[[:space:]>]|<style[[:space:]>]' -- $(PLUGIN_FILE) uninstall.php includes
	! grep -RInE 'wp_ai_client|WordPressAiEmbeddingProvider|generate_embeddings' -- $(PLUGIN_FILE) uninstall.php includes assets
	! grep -RInE 'badge_html\([^)]*\) \. \$$title|badge_html\([^)]*\) \. \(string\) \$$title' includes/SearchInterceptor.php
	grep -RIn 'wp_remote_post("https://api.openai.com/v1/embeddings"' includes/Embedding >/dev/null
	grep -RIn 'WordPress 7.0.*AI Client.*embeddings API' readme.txt >/dev/null
	grep -RIn 'OpenAI.*Terms of use https://openai.com/policies/terms-of-use/.*Privacy policy https://openai.com/policies/privacy-policy/' readme.txt >/dev/null
	grep -RIn 'Azure OpenAI.*Terms of use https://www.microsoft.com/licensing/terms/productoffering/MicrosoftAzure/MCA.*Privacy statement https://privacy.microsoft.com/privacystatement' readme.txt >/dev/null
	grep -RIn 'Local or self-hosted endpoints.*Ollama.*LM Studio.*Infinity.*TEI.*Custom HTTP' readme.txt >/dev/null
	grep -RIn 'private const CACHE_MAX_ENTRIES = 100;' includes/SearchInterceptor.php >/dev/null
	grep -RIn 'remember_cache_key' includes/SearchInterceptor.php >/dev/null
	grep -RIn 'delete_option(self::CACHE_INDEX_OPTION)' includes/SearchInterceptor.php >/dev/null

static-check: composer-validate lint phpcs-security composer-audit compose-config i18n-pot-check release-audit review-audit

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
	! unzip -l $(ZIP_FILE) | grep -E 'ai-retriever|wp-retriever|wp_retriever' >/dev/null
	! unzip -l $(ZIP_FILE) | grep -E '/(tmp|vendor|build|dist|\\.git)/' >/dev/null

release: check
