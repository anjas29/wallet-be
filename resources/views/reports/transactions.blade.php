<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 90px 30px 60px 30px;
        }

        body {
            font-family: "Helvetica", "Arial", sans-serif;
            font-size: 11px;
            color: #222;
        }

        .header {
            position: fixed;
            top: -70px;
            left: 0px;
            right: 0px;
            height: 60px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 8px;
        }

        .header h1 {
            margin: 0;
            font-size: 16px;
        }

        .header .meta {
            color: #555;
            font-size: 10px;
        }

        .footer {
            position: fixed;
            bottom: -50px;
            left: 0px;
            right: 0px;
            height: 30px;
            border-top: 1px solid #ccc;
            padding-top: 6px;
            font-size: 9px;
            color: #777;
            text-align: center;
        }

        h2 {
            font-size: 13px;
            margin-top: 24px;
            margin-bottom: 4px;
        }

        .account-meta {
            font-size: 10px;
            color: #555;
            margin-bottom: 6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        th, td {
            padding: 4px 6px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background-color: #f5f5f5;
            font-size: 10px;
            text-transform: uppercase;
        }

        td.amount, th.amount, td.balance, th.balance {
            text-align: right;
        }

        .totals td {
            border-top: 1px solid #ccc;
            border-bottom: none;
            font-weight: bold;
        }

        .summary {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 2px solid #333;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Transaction Report</h1>
        <div class="meta">{{ $user->name }} &bull; {{ $label }} &bull; Generated {{ $generatedAt->toDateTimeString() }}</div>
    </div>

    <div class="footer">
        Wallet App &mdash; Confidential financial statement &mdash; Generated {{ $generatedAt->toDateTimeString() }}
    </div>

    @forelse ($statements as $statement)
        @php $account = $statement['account']; @endphp
        <h2>{{ $account->name }}</h2>
        <div class="account-meta">
            {{ $account->userCurrency?->currency?->code }} &bull;
            Opening balance: {{ $statement['opening'] }} &bull;
            Closing balance: {{ $statement['closing'] }}
        </div>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Type</th>
                    <th class="amount">Amount</th>
                    <th class="balance">Balance</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($statement['entries'] as $entry)
                    <tr>
                        <td>{{ $entry['date']?->toDateString() }}</td>
                        <td>{{ $entry['description'] }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $entry['type'])) }}</td>
                        <td class="amount">{{ $entry['amount'] }}</td>
                        <td class="balance">{{ $entry['balance'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">No activity in this period.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="totals">
                    <td colspan="3">Totals</td>
                    <td class="amount" colspan="2">
                        Income: {{ number_format($statement['totalIncome'], 2) }} &bull;
                        Expense: {{ number_format($statement['totalExpense'], 2) }} &bull;
                        Transfers in: {{ number_format($statement['totalTransferIn'], 2) }} &bull;
                        Transfers out: {{ number_format($statement['totalTransferOut'], 2) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    @empty
        <p>No accounts found for this user.</p>
    @endforelse

    <div class="summary">
        <strong>Overall net change:</strong> {{ $summary['net'] }} {{ $summary['currencyCode'] }}
    </div>
</body>
</html>
