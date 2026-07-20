# Submit a frozen v4.x candidate to its trusted GitHub Actions gate.
.PHONY: ship ship-status

ship: ## Submit the committed v4.x snapshot (SHIP_DRY_RUN=1 previews without network or mutation)
	@scripts/dev/ship.sh $(if $(filter 1,$(SHIP_DRY_RUN)),--dry-run)

ship-status: ## Watch an exact candidate gate (FOLLOW=<sha> CANDIDATE_REF=<ref> BASE_SHA=<sha>)
	@FOLLOW='$(FOLLOW)' CANDIDATE_REF='$(CANDIDATE_REF)' BASE_SHA='$(BASE_SHA)' \
	  WATCH='$(WATCH)' HISTORY='$(HISTORY)' HISTORY_N='$(HISTORY_N)' \
	  SHIP_STATUS_DISCOVERY_ATTEMPTS='$(SHIP_STATUS_DISCOVERY_ATTEMPTS)' \
	  SHIP_STATUS_DISCOVERY_DELAY_SECONDS='$(SHIP_STATUS_DISCOVERY_DELAY_SECONDS)' \
	  scripts/dev/ship.sh status
