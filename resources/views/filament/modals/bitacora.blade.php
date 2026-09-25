@php
    $fmt = fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp
<div style="display:grid; gap:.75rem; font-size:.8125rem">
    <div><strong>{{ \App\Filament\Resources\AuditLogs\AuditLogResource::ACCIONES[$log->action] ?? $log->action }}</strong>
        · {{ $log->created_at?->format('d/m/Y H:i:s') }} · {{ $log->user_email ?? 'sistema' }} · IP {{ $log->ip_address ?? '—' }}</div>
    @if ($log->auditable_type)
        <div style="opacity:.7">{{ $log->auditable_type }} #{{ $log->auditable_id }}</div>
    @endif
    @if ($log->old_values)
        <div><div style="font-weight:600; margin-bottom:.25rem">Antes</div>
            <pre style="white-space:pre-wrap; overflow-wrap:anywhere; font-size:.75rem; padding:.5rem; border-radius:.5rem; background:rgba(128,128,128,.08)">{{ $fmt($log->old_values) }}</pre></div>
    @endif
    @if ($log->new_values)
        <div><div style="font-weight:600; margin-bottom:.25rem">Detalle</div>
            <pre style="white-space:pre-wrap; overflow-wrap:anywhere; font-size:.75rem; padding:.5rem; border-radius:.5rem; background:rgba(128,128,128,.08)">{{ $fmt($log->new_values) }}</pre></div>
    @endif
    <div style="opacity:.6; font-size:.75rem">{{ $log->user_agent }}</div>
</div>
