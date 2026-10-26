@foreach($monthlyRows as $row)
    @php($employee = $row['employee'])
    <tr data-employee-id="{{ $employee->id }}">
        <td>{{ $employee->employee_code }}</td>
        <td><a class="employee-link fw-bold" href="{{ route('employees.show', $employee) }}">{{ $employee->full_name }}</a></td>
        <td>{{ $employee->department?->name ?? '—' }}</td>
        <td>{{ format_number($row['attendance_days']) }}</td><td>{{ format_number($row['late_days']) }}</td><td>{{ format_number($row['late_minutes']) }}</td><td>{{ format_number($row['absence_days']) }}</td>
        <td>{{ format_number($row['early_leave_minutes']) }}</td><td>{{ format_number($row['day_overtime_minutes']) }}</td><td>{{ format_number($row['night_overtime_minutes']) }}</td>
        <td>{{ format_number($row['annual_leave_days']) }}</td><td>{{ format_number($row['casual_leave_days']) }}</td><td>{{ format_number($row['sick_leave_days']) }}</td><td>{{ format_number($row['unpaid_leave_days']) }}</td><td>{{ format_number($row['maternity_leave_days']) }}</td><td>{{ format_number($row['other_leave_days']) }}</td><td>{{ format_number($row['total_leave_days']) }}</td><td><span class="badge-soft success">{{ format_number($row['annual_balance_remaining']) }} يوم</span></td>
        <td>{{ format_number($row['penalty_count']) }}</td><td>{{ format_number($row['penalty_days']) }}</td>
        <td>{{ format_number($row['advance_request_count']) }}</td><td>{{ format_number($row['permission_request_count']) }}</td><td>{{ format_number($row['mission_request_count']) }}</td>
    </tr>
@endforeach
