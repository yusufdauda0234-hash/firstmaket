@php
    $brandBlue = '#0049AD';
    $brandNavy = '#102A5E';
    $brandYellow = '#FFDF58';
    // A CSS wordmark (not an image) — so the message carries no attachment
    // and the logo never breaks, even when the app has no public host yet.
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $title ?? 'FirstMaket' }}</title>
    <!--[if mso]>
    <style>* { font-family: Arial, sans-serif !important; }</style>
    <![endif]-->
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; }
        a { color: {{ $brandBlue }}; }

        @media (prefers-color-scheme: dark) {
            .fm-body { background: #0b1220 !important; }
            .fm-card { background: #131c2e !important; border-color: #24314a !important; }
            .fm-text { color: #e6ebf5 !important; }
            .fm-muted { color: #9fb0cc !important; }
            .fm-code-box { background: #0e2a5c !important; border-color: #2f60ad !important; }
            .fm-code { color: #ffffff !important; }
            .fm-divider { border-color: #24314a !important; }
            .fm-footer { color: #7f8ba6 !important; }
        }

        @media only screen and (max-width: 600px) {
            .fm-container { width: 100% !important; }
            .fm-px { padding-left: 24px !important; padding-right: 24px !important; }
            .fm-code { font-size: 34px !important; letter-spacing: 10px !important; }
        }
    </style>
</head>
<body class="fm-body" style="margin:0; padding:0; background-color:#eef2f8; font-family:'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <!-- Preheader (hidden preview text) -->
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#eef2f8; opacity:0;">
        {{ $preheader ?? 'FirstMarket— Just Order. We Deliver.' }}
        &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="fm-body" style="background-color:#eef2f8;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" class="fm-container" style="width:480px; max-width:480px;">

                    <!-- Header -->
                    <tr>
                        <td style="border-radius:24px 24px 0 0; background-color:{{ $brandBlue }}; background-image:linear-gradient(135deg, {{ $brandBlue }} 0%, {{ $brandNavy }} 100%); padding:34px 40px 28px;" align="center">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
                                <tr>
                                    <!-- Bag badge -->
                                    <td style="vertical-align:middle; padding-right:12px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="width:40px; height:40px; border-radius:11px; background-color:{{ $brandYellow }}; text-align:center; vertical-align:middle; font-size:22px; line-height:40px; font-weight:800; color:{{ $brandNavy }}; font-family:'Segoe UI', Arial, sans-serif;">
                                                    F
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <!-- Wordmark -->
                                    <td style="vertical-align:middle; text-align:left;">
                                        <div style="font-size:24px; font-weight:800; letter-spacing:0.5px; line-height:1; font-family:'Segoe UI', Arial, sans-serif;">
                                            <span style="color:{{ $brandYellow }};">First</span><span style="color:#ffffff;">Market</span>
                                        </div>
                                        <div style="font-size:10px; letter-spacing:2.5px; text-transform:uppercase; color:rgba(255,255,255,0.65); margin-top:5px; font-family:'Segoe UI', Arial, sans-serif;">
                                            Just Order. We Deliver.
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Yellow accent rule -->
                    <tr>
                        <td style="height:4px; line-height:4px; font-size:0; background-color:{{ $brandYellow }};">&nbsp;</td>
                    </tr>

                    <!-- Card body -->
                    <tr>
                        <td class="fm-card fm-px" style="background-color:#ffffff; padding:40px; border-left:1px solid #e6ebf3; border-right:1px solid #e6ebf3;">
                            @yield('content')
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="fm-card fm-px" style="background-color:#ffffff; padding:0 40px 36px; border-left:1px solid #e6ebf3; border-right:1px solid #e6ebf3; border-radius:0 0 24px 24px;">
                            <hr class="fm-divider" style="border:none; border-top:1px solid #eef2f8; margin:0 0 20px;">
                            <p class="fm-footer" style="margin:0 0 4px; font-size:12px; line-height:18px; color:#98a6bf;">
                                FirstMarket— Just Order. We Deliver.
                            </p>
                            <p class="fm-footer" style="margin:0 0 12px; font-size:12px; line-height:18px; color:#98a6bf;">
                                Verified vendors · Secure Paystack checkout · Nationwide delivery.
                            </p>
                            <p class="fm-footer" style="margin:0; font-size:11px; line-height:17px; color:#b4c0d6;">
                                You received this email because someone used this address on FirstMaket.
                                If this wasn't you, you can safely ignore it.
                            </p>
                            <p class="fm-footer" style="margin:12px 0 0; font-size:11px; line-height:17px; color:#b4c0d6;">
                                &copy; {{ date('Y') }} FirstMaket. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
