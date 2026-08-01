<div class="flex items-center gap-2 pb-4">
    <a href="{{ route('project.application.deployment.index', ['project_uuid' => $application->project()->uuid, 'environment_uuid' => $application->environment->uuid, 'application_uuid' => $application->uuid]) }}"
        {{ wireNavigate() }} title="Back to Deployments"
        class="flex items-center gap-1 pr-1 text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-white">
        <svg class="w-4 h-4" viewBox="0 0 24 24">
            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                stroke-width="2" d="m14 6l-6 6l6 6z" />
        </svg>
        Deployments
    </a>
    <h2>Deployment Log</h2>
    @if (data_get($application_deployment_queue, 'status') === 'queued')
        <x-forms.button wire:click.prevent="force_start">Force Start</x-forms.button>
    @endif
    @if (
            data_get($application_deployment_queue, 'status') === 'in_progress' ||
            data_get($application_deployment_queue, 'status') === 'queued'
        )
        <x-forms.button isError wire:click.prevent="cancel">Cancel</x-forms.button>
    @endif
</div>