# Publish an exact signed fork release from the main tip.
.PHONY: ship ship-status

ship: ## Sign, push, and watch a fork tag from clean main (SHIP_DRY_RUN=1 previews only)
	@SHIP_STATUS_DISCOVERY_ATTEMPTS='$(SHIP_STATUS_DISCOVERY_ATTEMPTS)' \
	  SHIP_STATUS_DISCOVERY_DELAY_SECONDS='$(SHIP_STATUS_DISCOVERY_DELAY_SECONDS)' \
	  SHIP_STATUS_LIMIT='$(SHIP_STATUS_LIMIT)' \
	  scripts/dev/ship.sh $(if $(filter 1,$(SHIP_DRY_RUN)),--dry-run)

ship-status: ## Report one exact publish run (SHIP_TAG=X.Y.Z-fork SHIP_SHA=<40-hex>)
	@SHIP_STATUS_LIMIT='$(SHIP_STATUS_LIMIT)' \
	  scripts/dev/ship.sh status --tag '$(SHIP_TAG)' --sha '$(SHIP_SHA)'
