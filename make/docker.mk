# Local OrbStack Docker SSOT hygiene for Coolify dev.
# Clean only explicit lab resources: cpbg-*, production-application-blue-green-*,
# or coolify.integration.ephemeral=true. It never prunes global Docker state.

.PHONY: docker-ssot-status docker-ssot-clean docker-ssot-ensure-builder

DOCKER_PUSH_BUILDER ?= aventure-runtime-multiarch-proxy
export DOCKER_PUSH_BUILDER

docker-ssot-status: ## Print explicit cleanup targets and live Docker inventory
	@scripts/dev/docker-ssot-clean.sh status

docker-ssot-ensure-builder: ## Ensure multiarch push builder (aventure-runtime-multiarch-proxy)
	@scripts/dev/docker-ssot-clean.sh ensure-builder

docker-ssot-clean: ## Remove only explicitly identified Coolify lab resources
	@scripts/dev/docker-ssot-clean.sh clean
