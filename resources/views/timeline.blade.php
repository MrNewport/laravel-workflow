{{-- The host must authorize this run before rendering; this view performs no mutations. --}}
<section aria-label="{{ __('laravel-workflow::messages.history') }}" class="rounded-2xl border border-slate-200 bg-white p-5 text-slate-900 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">{{ $run->publishedDefinition->name }} · {{ __('laravel-workflow::messages.version', ['version' => $run->publishedDefinition->version]) }}</h2>
        <span @class(['rounded-full px-3 py-1 text-xs font-medium', 'bg-blue-50 text-blue-800 dark:bg-blue-950 dark:text-blue-200' => $run->status === 'active', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' => $run->status === 'completed', 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300' => $run->status === 'cancelled'])>{{ __('laravel-workflow::messages.status.'.$run->status) }}</span>
    </div>
    @php($history = $history ?? $run->history())
    <ol class="space-y-5" aria-label="{{ __('laravel-workflow::messages.actions') }}">
        @foreach ($history as $command)
            <li class="border-l-2 border-slate-200 pl-4 dark:border-slate-700">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="font-medium">{{ \Illuminate\Support\Facades\Lang::has('laravel-workflow::messages.action.'.$command->action) ? __('laravel-workflow::messages.action.'.$command->action) : ucfirst(str_replace('-', ' ', $command->action)) }}</span>
                    <span class="text-sm text-slate-500 dark:text-slate-400">{{ $command->actor }}</span>
                    <time datetime="{{ $command->created_at->toIso8601String() }}" class="text-xs text-slate-500 dark:text-slate-400">{{ $command->created_at->utc()->format('Y-m-d H:i') }} UTC</time>
                </div>
                @foreach ($command->messages as $message)
                    <p class="mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed">{{ $message->body }}</p>
                @endforeach
                @if ($command->messages_count > $command->messages->count())
                    <p class="mt-2 text-sm">{{ __('laravel-workflow::messages.message_preview', ['shown' => $command->messages->count(), 'total' => $command->messages_count]) }}</p>
                    @if (isset($messageHistoryUrl))
                        <a class="underline focus-visible:outline-2" href="{{ $messageHistoryUrl($command) }}">{{ __('laravel-workflow::messages.all_messages') }}</a>
                    @endif
                @endif
            </li>
        @endforeach
    </ol>
    {{ $history->links() }}
</section>
