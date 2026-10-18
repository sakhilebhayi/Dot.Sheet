@extends('errors._ecosystem-layout', ['code' => 413])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#e3ab1f" stroke-width="1.75"><path d="M4 7h16M4 12h16M4 17h10" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('title', 'This is too big to do in one go')
@section('message', ($exception ?? null)?->getMessage() ?: 'This is more than the server can handle in a single request. Nothing was changed.')

@section('actions')
    <a href="{{ url()->previous() === url()->current() ? '/' : url()->previous() }}" class="press" style="padding: 12px 24px; background: var(--accent); color: #12231f; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Go back</a>
@endsection
