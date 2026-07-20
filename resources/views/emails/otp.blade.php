@extends('emails.layout', ['title' => 'Your verification code', 'preheader' => 'Your FirstMarket verification code — expires in '.$ttlMinutes.' minutes.'])

@section('content')
    @php
        $brandBlue = '#0049AD';
        $brandNavy = '#102A5E';
    @endphp

    <!-- Icon badge -->
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr>
            <td style="width:52px; height:52px; border-radius:16px; background-color:#eef4fc; text-align:center; vertical-align:middle; font-size:26px; line-height:52px;">
                🔐
            </td>
        </tr>
    </table>

    <h1 class="fm-text" style="margin:0 0 8px; font-size:22px; line-height:28px; font-weight:800; color:#0f1b33; letter-spacing:-0.3px;">
        Verify it's really you
    </h1>
    <p class="fm-muted" style="margin:0 0 28px; font-size:15px; line-height:23px; color:#5b6b86;">
        Use the code below to continue on FirstMarket. It keeps your account secure.
    </p>

    <!-- Code box -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px;">
        <tr>
            <td class="fm-code-box" align="center" style="background-color:#f4f7ff; border:1px solid #d4e2fb; border-radius:16px; padding:26px 16px;">
                <div class="fm-muted" style="font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:#7d8daa; margin-bottom:10px;">
                    Your verification code
                </div>
                <div class="fm-code" style="font-family:'Courier New', Consolas, monospace; font-size:42px; font-weight:800; letter-spacing:14px; color:{{ $brandNavy }}; padding-left:14px;">
                    {{ $code }}
                </div>
            </td>
        </tr>
    </table>

    <!-- Expiry chip -->
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
        <tr>
            <td style="background-color:#fff7db; border-radius:999px; padding:7px 14px; font-size:12px; font-weight:600; color:#8a6d1a;">
                ⏱&nbsp; Expires in {{ $ttlMinutes }} minutes
            </td>
        </tr>
    </table>

    <hr class="fm-divider" style="border:none; border-top:1px solid #eef2f8; margin:0 0 20px;">

    <p class="fm-muted" style="margin:0; font-size:13px; line-height:20px; color:#8493ad;">
        🛡️ For your security, never share this code with anyone — not even FirstMarket staff.
        We will never call, text, or email you asking for it.
    </p>
@endsection
