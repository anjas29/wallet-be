<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Wallet API</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6; max-width: 860px; margin: 0 auto; padding: 2rem 1.25rem 6rem;
            color: #1f2328; background: #fff;
        }
        h1, h2, h3 { line-height: 1.25; margin-top: 2rem; }
        h1 { border-bottom: 1px solid #d0d7de; padding-bottom: .3rem; }
        h2 { border-bottom: 1px solid #d0d7de; padding-bottom: .3rem; margin-top: 2.5rem; }
        a { color: #0969da; }
        code { font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace; font-size: .9em; }
        :not(pre) > code { background: #eff1f3; padding: .15em .4em; border-radius: 6px; }
        pre {
            background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 8px;
            padding: 1rem; overflow-x: auto; font-size: .85rem; line-height: 1.5;
        }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
        th, td { border: 1px solid #d0d7de; padding: .4rem .75rem; text-align: left; }
        th { background: #f6f8fa; }
        hr { border: none; border-top: 1px solid #d0d7de; margin: 2rem 0; }
        @media (prefers-color-scheme: dark) {
            body { color: #e6edf3; background: #0d1117; }
            h1, h2 { border-color: #30363d; }
            :not(pre) > code { background: #6e768166; }
            pre { background: #161b22; border-color: #30363d; }
            th, td { border-color: #30363d; }
            th { background: #161b22; }
            hr { border-color: #30363d; }
            a { color: #4493f8; }
        }
    </style>
</head>
<body>
{!! $content !!}
</body>
</html>
