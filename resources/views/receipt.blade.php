<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Money Receipt</title>

    <style>
        @page {
            size: 170mm 160mm;
            margin: 5mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            background: #ffffff;
            color: #334155;
            margin: 0;
            padding: 0;
        }

        /* =========================
           RECEIPT
        ========================= */
        .receipt {
            max-width: 650px;
            margin: auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }

        /* =========================
           HEADER
        ========================= */
        .header {
            padding: 12px 18px 8px;
        }

        .brand {
            font-size: 26px;
            font-weight: 700;
            color: #2563eb;
            margin: 0;
            line-height: 1.1;
        }

        .receipt-title {
            margin-top: 5px;
            font-size: 14px;
            color: #64748b;
        }

        .receipt-title span {
            color: #2563eb;
            font-weight: bold;
            margin: 0 5px;
        }

        .receipt-info {
            text-align: right;
            font-size: 11px;
            color: #475569;
            line-height: 1.7;
        }

        .receipt-id {
            background: #2563eb;
            color: #ffffff;
            padding: 3px 9px;
            border-radius: 5px;
            font-weight: 700;
        }

        .top-line {
            height: 2px;
            background: #2563eb;
            margin-top: 8px;
        }

        /* =========================
           INSTITUTE LOGO
        ========================= */
        .logo-wrapper {
            width: 58px;
            height: 58px;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            text-align: center;
            vertical-align: middle;
            background: #ffffff;
            padding: 3px;
        }

        .institute-logo {
            width: 52px;
            height: 52px;
            object-fit: contain;
        }

        .logo-placeholder {
            width: 52px;
            height: 52px;
            line-height: 52px;
            text-align: center;
            font-size: 24px;
            color: #2563eb;
            font-weight: bold;
        }

        .brand-area {
            padding-left: 10px;
            vertical-align: middle;
        }

        /* =========================
           CONTENT
        ========================= */
        .content {
            padding: 10px 18px 12px;
        }

        /* =========================
           INFO BOX
        ========================= */
        .info-box {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            background: #ffffff;
        }

        .info-header {
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
        }

        .student-header {
            background: #eff6ff;
            color: #2563eb;
        }

        .payment-header {
            background: #ecfdf5;
            color: #059669;
        }

        .info-content {
            padding: 6px 12px;
        }

        .info-row {
            padding: 4px 0;
            font-size: 11px;
            color: #475569;
            border-bottom: 1px solid #f1f5f9;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .label {
            width: 55px;
            display: inline-block;
            font-weight: 700;
            color: #334155;
        }

        /* =========================
           STATUS
        ========================= */
        .status {
            background: #16a34a;
            color: #ffffff;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
        }

        /* =========================
           PAYMENT TABLE
        ========================= */
        .payment-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        .payment-table th {
            background: #2563eb;
            color: #ffffff;
            padding: 7px;
            font-size: 11px;
            text-align: left;
        }

        .payment-table td {
            border: 1px solid #e2e8f0;
            padding: 7px;
            font-size: 11px;
            color: #334155;
        }

        /* =========================
           TOTAL BOX
        ========================= */
        .total-box {
            margin-top: 12px;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            background: #f8fafc;
            padding: 10px 14px;
        }

        .total-title {
            font-size: 15px;
            font-weight: 700;
            color: #2563eb;
        }

        .total-amount {
            font-size: 21px;
            font-weight: 700;
            color: #2563eb;
            text-align: right;
        }

        /* =========================
           FOOTER
        ========================= */
        .footer {
            text-align: center;
            padding: 8px 10px 12px;
            font-size: 11px;
            color: #2563eb;
            font-style: italic;
        }

        .line {
            width: 45px;
            height: 1px;
            background: #2563eb;
            display: inline-block;
            vertical-align: middle;
            margin: 0 6px;
        }

        /* =========================
           PRINT
        ========================= */
        @media print {
            body {
                background: #ffffff;
            }

            .receipt {
                box-shadow: none;
            }
        }
    </style>
</head>


<body>

<div class="receipt">

    <!-- =========================
         HEADER
    ========================== -->
    <div class="header">

        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>

                <!-- LOGO -->
                <td width="65" valign="middle">

                    @if($institute?->logo)

                        <div class="logo-wrapper">

                            <img
                                src="{{ public_path('storage/' . $institute->logo) }}"
                                class="institute-logo"
                                alt="Institute Logo"
                            >

                        </div>

                    @else

                        <div class="logo-wrapper">

                            <div class="logo-placeholder">
                                +
                            </div>

                        </div>

                    @endif

                </td>


                <!-- INSTITUTE NAME -->
                <td class="brand-area">

                    <h1 class="brand">
                        {{ $institute?->institute_name ?? 'Betikrom Academic Care' }}
                    </h1>

                    <div class="receipt-title">
                        <span>—</span>
                        Money Receipt
                        <span>—</span>
                    </div>

                </td>


                <!-- RECEIPT INFORMATION -->
                <td class="receipt-info" width="180">

                    <strong>Receipt No:</strong>

                    <span class="receipt-id">
                        #{{ $payment->id }}
                    </span>

                    <br>

                    <strong>Date:</strong>
                    {{ $payment->payment_date ?? 'N/A' }}

                </td>

            </tr>
        </table>

        <div class="top-line"></div>

    </div>


    <!-- =========================
         CONTENT
    ========================== -->
    <div class="content">


        <!-- =========================
             STUDENT + PAYMENT INFO
        ========================== -->
        <table width="100%" cellpadding="0" cellspacing="0">

            <tr>

                <!-- =========================
                     STUDENT INFO
                ========================== -->
                <td width="49%" valign="top">

                    <div class="info-box">

                        <div class="info-header student-header">
                            STUDENT INFO
                        </div>

                        <div class="info-content">


                            <!-- NAME -->
                            <div class="info-row">

                                <span class="label">
                                    Name
                                </span>

                                {{ $payment->student?->full_name ?? 'N/A' }}

                            </div>


                            <!-- ID -->
                            <div class="info-row">

                                <span class="label">
                                    ID
                                </span>

                                {{ $payment->student?->student_id ?? 'N/A' }}

                            </div>


                            <!-- CLASS -->
                            <div class="info-row">

                                <span class="label">
                                    Class
                                </span>

                                {{ $payment->student?->classInfo?->class_name ?? 'N/A' }}

                            </div>


                            <!-- VERSION -->
                            <div class="info-row">

                                <span class="label">
                                    Version
                                </span>

                                {{ $payment->student?->version ?? 'N/A' }}

                            </div>


                            <!-- SECTION -->
                            <div class="info-row">

                                <span class="label">
                                    Section
                                </span>

                                {{ $payment->student?->section?->section_name ?? 'N/A' }}

                            </div>


                            <!-- PHONE -->
                            <div class="info-row">

                                <span class="label">
                                    Phone
                                </span>

                                {{ $payment->student?->phone ?? 'N/A' }}

                            </div>


                        </div>

                    </div>

                </td>


                <td width="2%"></td>


                <!-- =========================
                     PAYMENT INFO
                ========================== -->
                <td width="49%" valign="top">

                    <div class="info-box">

                        <div class="info-header payment-header">
                            PAYMENT INFO
                        </div>

                        <div class="info-content">


                            <!-- MONTH -->
                            <div class="info-row">

                                <span class="label">
                                    Month
                                </span>

                               {{ $payment->month ?? $payment->due_month ?? 'N/A' }}

                            </div>


                            <!-- METHOD -->
                            <div class="info-row">

                                <span class="label">
                                    Method
                                </span>

                                {{ $payment->payment_method ?? 'N/A' }}

                            </div>


                            <!-- STATUS -->
                            <div class="info-row">

                                <span class="label">
                                    Status
                                </span>

                                <span class="status">
                                    {{ ucfirst($payment->status ?? 'N/A') }}
                                </span>

                            </div>


                        </div>

                    </div>

                </td>

            </tr>

        </table>


        <!-- =========================
             PAYMENT TABLE
        ========================== -->
        <table class="payment-table">

            <thead>

                <tr>

                    <th width="10%">
                        #
                    </th>

                    <th>
                        Description
                    </th>

                    <th width="30%">
                        Amount
                    </th>

                </tr>

            </thead>


            <tbody>
    @php
        $isStandaloneDuePayment = empty($payment->month)
            && !empty($payment->due_month);

        $hasPreviousDuePayment = !empty($payment->due_month)
            && (float) ($payment->due_payment_amount ?? 0) > 0;

        $rowNumber = 1;
    @endphp

    {{-- MONTHLY FEE --}}
    @if(!$isStandaloneDuePayment)
        <tr>
            <td>{{ $rowNumber++ }}</td>
            <td>
                Monthly Coaching Fee — {{ $payment->month ?? 'N/A' }}
            </td>
            <td>
                BDT {{ number_format((float) ($payment->amount ?? 0), 2) }}
            </td>
        </tr>

        {{-- CURRENT MONTH PAID AMOUNT --}}
        <tr>
            <td>{{ $rowNumber++ }}</td>
            <td>
                <strong>Paid Amount</strong>
            </td>
            <td>
                <strong>
                    BDT {{ number_format((float) ($payment->paid_amount ?? 0), 2) }}
                </strong>
            </td>
        </tr>
    @endif

    {{-- PREVIOUS MONTH DUE PAYMENT --}}
    @if($hasPreviousDuePayment)
        <tr>
            <td>{{ $rowNumber++ }}</td>
            <td>
                Due Payment — {{ $payment->due_month }}
            </td>
            <td>
                BDT {{ number_format((float) $payment->due_payment_amount, 2) }}
            </td>
        </tr>
    @endif

    {{-- ADMISSION FEE --}}
    @if((float) ($payment->admission_fee ?? 0) > 0)
        <tr>
            <td>{{ $rowNumber++ }}</td>
            <td>Admission Fee</td>
            <td>
                BDT {{ number_format((float) $payment->admission_fee, 2) }}
            </td>
        </tr>
    @endif

    {{-- EXAM FEE --}}
    @if((float) ($payment->exam_fee ?? 0) > 0)
        <tr>
            <td>{{ $rowNumber++ }}</td>
            <td>Exam Fee</td>
            <td>
                BDT {{ number_format((float) $payment->exam_fee, 2) }}
            </td>
        </tr>
    @endif
</tbody>

        </table>


        <!-- =========================
             TOTAL
        ========================== -->
        <div class="total-box">

            <table width="100%">

                <tr>

                    <td class="total-title">
                        TOTAL PAID
                    </td>

                    <td class="total-amount">

                        BDT {{ number_format(
                            ($payment->paid_amount ?? 0)
                            +
                            ($payment->admission_fee ?? 0)
                            +
                            ($payment->exam_fee ?? 0),
                            2
                        ) }}

                    </td>

                </tr>

            </table>

        </div>

    </div>


    <!-- =========================
         FOOTER
    ========================== -->
    <div class="footer">

        <span class="line"></span>

        Thank you for your payment

        <span class="line"></span>

    </div>

</div>

</body>

</html>
