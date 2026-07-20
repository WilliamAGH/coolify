<x-emails.layout>
Automatic blue/green recovery stopped because operator intervention is required for {{ $name }}.

@if ($fqdn)
The public application remains at [{{ $fqdn }}]({{ $fqdn }}).
@endif

@if ($deployment_url)
[View Deployment Logs]({{ $deployment_url }})
@else
[Open Application]({{ $application_url }})
@endif
</x-emails.layout>
