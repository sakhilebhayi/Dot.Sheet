{{-- R-61: InfoDot has switched Dot.Sheet off (maintenance or unavailable). Says so plainly and reads nothing of the company's
     work: the workbooks already here are kept exactly as they are. Not a purchase or permission problem. --}}
@extends('errors._ecosystem-layout', ['code' => 503])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#e3ab1f" stroke-width="1.75"><path d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 005.4-5.4l-2.65 2.65a1.5 1.5 0 01-2.1-2.1L14.7 6.3z" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('title', 'Dot.Sheet is not available right now')
@section('message', ($message ?? 'Dot.Sheet is temporarily unavailable.').' The workbooks already here are kept as they are.')

@section('nav')
    @auth
        <form method="POST" action="{{ route('logout') }}" style="margin: 0;">
            @csrf
            <button type="submit" class="link-underline" style="background-color: transparent; border: 0; padding: 0 0 1px; cursor: pointer; color: rgba(255,255,255,0.85); font: inherit; font-size: 14px;">Sign out</button>
        </form>
    @endauth
@endsection
