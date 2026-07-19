<x-emails.layout>
Coolify restored the previous healthy version of {{ $name }} after an interrupted blue/green deployment.

@if ($fqdn)
The public application is available at [{{ $fqdn }}]({{ $fqdn }}).
@endif

[View Deployment Logs]({{ $deployment_url }})
</x-emails.layout>
