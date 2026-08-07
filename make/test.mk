.PHONY: test-blue-green-postgresql

test-blue-green-postgresql: ## Run the canonical blue-green lane against disposable local PostgreSQL
	@env FILTER="$(FILTER)" bash scripts/dev/test-blue-green-postgresql.sh local
