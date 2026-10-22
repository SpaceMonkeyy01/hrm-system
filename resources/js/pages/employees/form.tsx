import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import type { FormEvent } from 'react';
import { Field } from '@/components/field';
import { NativeSelect, toOptions } from '@/components/native-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

type Company = { id: number; name: string; nextCode: string; departments: { id: number; name: string; head_id: number | null; teams: { id: number; name: string }[] }[] };
type EmployeeData = {
    id: number; employee_code: string; full_name: string; first_name: string | null; last_name: string | null; cnic: string | null;
    email: string | null; phone: string | null; company_id: number | null; department_id: number | null; team_id: number | null;
    role_title: string | null; manager_id: number | null; employment_type: string | null; start_date: string | null;
    hr_portal_id: string | null; attendance_machine: string | null; attendance_machine_id: string | null; drive_folder_url: string | null;
    notes: string | null; needs_review: boolean; review_note: string | null;
    secondary_department_id: number | null; secondary_manager_id: number | null; attendance_device_id: number | null; category_ids: number[];
} & Record<(typeof profileFields)[number], string | null>;
type Options = { genders: string[]; maritalStatuses: string[]; bloodGroups: string[]; workModes: string[]; historyTypes: string[] };
type Props = {
    employee: (EmployeeData & { work_schedule_id: number | null }) | null; companies: Company[]; managers: { id: number; label: string }[];
    employmentTypes: string[]; options: Options; schedules: { id: number; label: string; is_default: boolean }[];
    categories: { id: number; name: string }[]; devices: { id: number; label: string }[];
    prefill?: { name?: string; machine?: string; hrmy?: string; from?: 'links' } | null;
};

/** Master profile fields, all nullable strings (dates as Y-m-d). */
const profileFields = [
    'father_name', 'date_of_birth', 'gender', 'marital_status', 'blood_group', 'cnic_expiry', 'personal_email', 'alternate_phone',
    'current_address', 'permanent_address', 'city', 'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone',
    'bank_name', 'bank_account_title', 'bank_account_number', 'work_mode', 'work_location', 'confirmation_date',
] as const;

/** Changing one of these on Edit adds a line to the employment history (EmployeeController@update). */
const jobFields = ['company_id', 'department_id', 'team_id', 'role_title', 'manager_id', 'employment_type', 'work_mode'] as const;

export default function EmployeeForm({ employee, companies, managers, employmentTypes, options, schedules, categories, devices, prefill }: Props) {
    const editing = employee !== null;
    const fromLinks = !editing && prefill?.from === 'links';
    const form = useForm({
        company_id: employee?.company_id ?? '',
        employee_code: employee?.employee_code ?? '',
        full_name: employee?.full_name ?? prefill?.name ?? '',
        first_name: employee?.first_name ?? '',
        last_name: employee?.last_name ?? '',
        cnic: employee?.cnic ?? '',
        email: employee?.email ?? '',
        phone: employee?.phone ?? '',
        department_id: employee?.department_id ?? '',
        team_id: employee?.team_id ?? '',
        role_title: employee?.role_title ?? '',
        manager_id: employee?.manager_id ?? '',
        secondary_department_id: employee?.secondary_department_id ?? '',
        secondary_manager_id: employee?.secondary_manager_id ?? '',
        category_ids: employee?.category_ids ?? ([] as number[]),
        attendance_device_id: employee?.attendance_device_id ?? '',
        // Default only for a new hire: on edit a blank type must stay blank, not become Full Time.
        employment_type: employee === null ? 'Full Time' : (employee.employment_type ?? ''),
        start_date: employee?.start_date ?? '',
        hr_portal_id: employee?.hr_portal_id ?? prefill?.hrmy ?? '',
        attendance_machine: employee?.attendance_machine ?? '',
        attendance_machine_id: employee?.attendance_machine_id ?? prefill?.machine ?? '',
        drive_folder_url: employee?.drive_folder_url ?? '',
        notes: employee?.notes ?? '',
        needs_review: employee?.needs_review ?? false,
        review_note: employee?.review_note ?? '',
        ...(Object.fromEntries(profileFields.map((f) => [f, employee?.[f] ?? ''])) as Record<(typeof profileFields)[number], string>),
        work_schedule_id: employee?.work_schedule_id ?? '',
        schedule_starts_on: '',
        change_effective_date: '',
        change_type: '',
        change_note: '',
        // Someone found on Attendance > Links usually works here already: no onboarding unless HR ticks it.
        start_onboarding: !fromLinks,
        from: fromLinks ? 'links' : '',
    });
    const d = form.data;
    const company = companies.find((c) => c.id === Number(d.company_id));
    const department = company?.departments.find((x) => x.id === Number(d.department_id));
    const unassignedCode = editing && employee.employee_code.startsWith('UNASSIGNED-');
    const toggleCategory = (id: number) => form.setData('category_ids', d.category_ids.includes(id) ? d.category_ids.filter((x) => x !== id) : [...d.category_ids, id]);
    const jobChanged = editing && jobFields.some((f) => String(d[f] ?? '') !== String(employee[f] ?? ''));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // empty strings -> null so Laravel's "nullable" rules accept them
        form.transform((data) => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])) as typeof data);
        if (editing) form.put(`/employees/${employee.id}`);
        else form.post('/employees');
    };

    // Opened from a profile's "+ CNIC"-style button (/edit#cnic): start at that field.
    useEffect(() => {
        const el = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
        if (el) {
            el.scrollIntoView({ block: 'center' });
            el.focus({ preventScroll: true });
        }
    }, []);

    const text = (name: keyof typeof d, label: string, props: Record<string, unknown> = {}, hint?: string) => (
        <Field label={label} htmlFor={name} error={form.errors[name]} hint={hint}>
            <Input id={name} value={String(d[name] ?? '')} onChange={(e) => form.setData(name, e.target.value as never)} aria-invalid={!!form.errors[name]} {...props} />
        </Field>
    );
    const select = (name: keyof typeof d, label: string, values: string[], placeholder = 'Not set') => (
        <Field label={label} htmlFor={name} error={form.errors[name]}>
            <NativeSelect id={name} value={String(d[name] ?? '')} placeholder={placeholder} onChange={(e) => form.setData(name, e.target.value as never)} options={toOptions(values)} />
        </Field>
    );
    const area = (name: keyof typeof d, label: string) => (
        <Field label={label} htmlFor={name} error={form.errors[name]} className="sm:col-span-2">
            <Textarea id={name} value={String(d[name] ?? '')} onChange={(e) => form.setData(name, e.target.value as never)} rows={2} />
        </Field>
    );

    return (
        <>
            <Head title={editing ? `Edit ${employee.full_name}` : 'Add employee'} />
            <PageHeader
                back={editing ? { href: `/employees/${employee.id}`, label: employee.full_name } : fromLinks ? { href: '/attendance/links?tab=numbers', label: 'Attendance links' } : { href: '/employees', label: 'Employees' }}
                title={editing ? `Edit ${employee.full_name}` : 'Add employee'}
                description={!editing && fromLinks
                    ? `Someone who punches ${prefill?.machine ? `as machine no. ${prefill.machine}` : `as HR.my ID ${prefill?.hrmy}`} but has no employee record. Saving adds them and links the number.`
                    : undefined}
                help={editing || fromLinks ? undefined : 'New employees start onboarding straight away, on probation for 90 days from the start date.'}
            />
            <form onSubmit={submit} className="grid max-w-4xl gap-8 px-4 py-6 md:px-6">
                <fieldset className="grid gap-4 sm:grid-cols-2">
                    <legend className="mb-3 font-bold">Company and code</legend>
                    <Field label="Company" htmlFor="company_id" error={form.errors.company_id}>
                        <NativeSelect id="company_id" value={d.company_id} placeholder={editing ? 'No company yet' : 'Choose a company'} required={!editing}
                            onChange={(e) => form.setData((prev) => ({ ...prev, company_id: e.target.value, department_id: '', team_id: '', secondary_department_id: '' }))}
                            options={companies.map((c) => ({ value: c.id, label: c.name }))} />
                    </Field>
                    {text('employee_code', 'Employee code', { placeholder: company ? company.nextCode : '' },
                        editing ? (unassignedCode ? 'Gets the company\'s next code when you set a company.' : 'Change only if the code was wrong.') : `Leave empty to use ${company?.nextCode ?? 'the next free code'}.`)}
                </fieldset>

                <fieldset className="grid gap-4 sm:grid-cols-2">
                    <legend className="mb-3 font-bold">Identity</legend>
                    {text('full_name', 'Full name', { required: true, autoFocus: !editing })}
                    {text('father_name', 'Father or spouse name')}
                    {text('first_name', 'First name')}
                    {text('last_name', 'Last name')}
                    {text('cnic', 'CNIC', { placeholder: '35202-1234567-1', inputMode: 'numeric' })}
                    {text('cnic_expiry', 'CNIC expiry', { type: 'date' })}
                    {text('date_of_birth', 'Date of birth', { type: 'date' })}
                    {select('gender', 'Gender', options.genders)}
                    {select('marital_status', 'Marital status', options.maritalStatuses)}
                    {select('blood_group', 'Blood group', options.bloodGroups)}
                </fieldset>

                <fieldset className="grid gap-4 sm:grid-cols-2">
                    <legend className="mb-3 font-bold">Contact</legend>
                    {text('email', 'Work email', { type: 'email' })}
                    {text('personal_email', 'Personal email', { type: 'email' })}
                    {text('phone', 'Phone', { type: 'tel' })}
                    {text('alternate_phone', 'Other phone', { type: 'tel' })}
                    {text('city', 'City')}
                    <div className="hidden sm:block" />
                    {area('current_address', 'Current address')}
                    {area('permanent_address', 'Permanent address')}
                </fieldset>

                <fieldset className="grid gap-4 sm:grid-cols-3">
                    <legend className="mb-3 font-bold">Emergency contact</legend>
                    {text('emergency_contact_name', 'Name')}
                    {text('emergency_contact_relation', 'Relation', { placeholder: 'Father, spouse...' })}
                    {text('emergency_contact_phone', 'Phone', { type: 'tel' })}
                </fieldset>

                <fieldset className="grid gap-4 sm:grid-cols-3">
                    <legend className="mb-3 font-bold">Salary account</legend>
                    {text('bank_name', 'Bank')}
                    {text('bank_account_title', 'Account title')}
                    {text('bank_account_number', 'Account number or IBAN')}
                </fieldset>

                <fieldset className="grid gap-4 sm:grid-cols-2">
                    <legend className="mb-3 font-bold">Work</legend>
                    <Field label="Department" htmlFor="department_id" error={form.errors.department_id}>
                        <NativeSelect id="department_id" value={d.department_id} disabled={!company} placeholder={company ? 'No department' : 'Choose a company first'}
                            onChange={(e) => form.setData((prev) => {
                                // New joiner with no manager yet: the department head is the starting suggestion.
                                const head = company?.departments.find((x) => x.id === Number(e.target.value))?.head_id;
                                const suggest = !employee && !prev.manager_id && head ? head : prev.manager_id;
                                return { ...prev, department_id: e.target.value, team_id: '', manager_id: suggest };
                            })}
                            options={(company?.departments ?? []).map((x) => ({ value: x.id, label: x.name }))} />
                    </Field>
                    <Field label="Team" htmlFor="team_id" error={form.errors.team_id}>
                        <NativeSelect id="team_id" value={d.team_id} disabled={!department} placeholder={department ? 'No team' : 'Choose a department first'}
                            onChange={(e) => form.setData('team_id', e.target.value)}
                            options={(department?.teams ?? []).map((x) => ({ value: x.id, label: x.name }))} />
                    </Field>
                    <Field label="Second department" htmlFor="secondary_department_id" error={form.errors.secondary_department_id} hint="Only for people who work in two departments.">
                        <NativeSelect id="secondary_department_id" value={d.secondary_department_id} disabled={!company} placeholder={company ? 'None' : 'Choose a company first'}
                            onChange={(e) => form.setData('secondary_department_id', e.target.value)}
                            options={(company?.departments ?? []).filter((x) => x.id !== Number(d.department_id)).map((x) => ({ value: x.id, label: x.name }))} />
                    </Field>
                    {text('role_title', 'Role')}
                    <Field label="Manager" htmlFor="manager_id" error={form.errors.manager_id}>
                        <NativeSelect id="manager_id" value={d.manager_id} placeholder="No manager" onChange={(e) => form.setData('manager_id', e.target.value)} options={managers.map((m) => ({ value: m.id, label: m.label }))} />
                    </Field>
                    <Field label="Second manager" htmlFor="secondary_manager_id" error={form.errors.secondary_manager_id}>
                        <NativeSelect id="secondary_manager_id" value={d.secondary_manager_id} placeholder="None" onChange={(e) => form.setData('secondary_manager_id', e.target.value)}
                            options={managers.filter((m) => m.id !== Number(d.manager_id)).map((m) => ({ value: m.id, label: m.label }))} />
                    </Field>
                    <Field label="Employment type" htmlFor="employment_type" error={form.errors.employment_type}>
                        <NativeSelect id="employment_type" value={d.employment_type} placeholder="Not set" onChange={(e) => form.setData('employment_type', e.target.value)} options={toOptions(employmentTypes)} />
                    </Field>
                    {text('start_date', 'Start date', { type: 'date' }, 'Probation ends 90 days later.')}
                    {text('confirmation_date', 'Confirmation date', { type: 'date' }, 'When probation was passed.')}
                    <Field label="Work mode" htmlFor="work_mode" error={form.errors.work_mode} hint="Decides where attendance comes from: the machine for Office, the online portal for Remote.">
                        <NativeSelect id="work_mode" value={d.work_mode} placeholder="Not set" onChange={(e) => form.setData('work_mode', e.target.value)} options={toOptions(options.workModes)} />
                    </Field>
                    {text('work_location', 'Office or location')}
                    <Field label="Work schedule" htmlFor="work_schedule_id" error={form.errors.work_schedule_id}
                        hint={schedules.length === 0 ? 'No schedules yet. Add them under Work schedules.' : `Office timings used for late and half-day rules.${schedules.some((s) => s.is_default) ? ' Empty means the default schedule.' : ''}`}>
                        <NativeSelect id="work_schedule_id" value={d.work_schedule_id} placeholder="Default schedule" onChange={(e) => form.setData('work_schedule_id', e.target.value)}
                            options={schedules.map((s) => ({ value: s.id, label: s.label }))} />
                    </Field>
                    {String(d.work_schedule_id) !== String(employee?.work_schedule_id ?? '') && d.work_schedule_id !== '' &&
                        text('schedule_starts_on', 'Schedule starts on', { type: 'date' }, editing ? 'Leave empty for today. Earlier days keep the old schedule.' : 'Leave empty to use the start date.')}
                </fieldset>

                {categories.length > 0 && (
                    <fieldset>
                        <legend className="mb-3 font-bold">Categories</legend>
                        <div className="flex flex-wrap gap-2">
                            {categories.map((c) => (
                                <label key={c.id} className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1 text-sm has-[:checked]:border-cascade has-[:checked]:bg-cascade/10">
                                    <input type="checkbox" className="size-3.5 accent-cascade" checked={d.category_ids.includes(c.id)} onChange={() => toggleCategory(c.id)} />
                                    {c.name}
                                </label>
                            ))}
                        </div>
                        {form.errors.category_ids && <p className="mt-2 text-sm text-destructive">{form.errors.category_ids}</p>}
                    </fieldset>
                )}

                {jobChanged && (
                    <fieldset className="grid gap-4 rounded-lg border bg-muted/40 p-4 sm:grid-cols-2">
                        <legend className="px-1 font-bold">Job change</legend>
                        <p className="text-sm text-muted-foreground sm:col-span-2">You changed a job detail. It is added to the employment history when you save.</p>
                        {text('change_effective_date', 'Takes effect on', { type: 'date' }, 'Leave empty for today.')}
                        <Field label="Type of change" htmlFor="change_type" error={form.errors.change_type} hint="Leave empty to work it out from what changed.">
                            <NativeSelect id="change_type" value={d.change_type} placeholder="Work it out" onChange={(e) => form.setData('change_type', e.target.value)} options={toOptions(options.historyTypes)} />
                        </Field>
                        {area('change_note', 'Note')}
                    </fieldset>
                )}

                <fieldset className="grid gap-4 sm:grid-cols-2">
                    <legend className="mb-3 font-bold">Systems</legend>
                    {text('hr_portal_id', 'HR.my ID')}
                    {text('drive_folder_url', 'Drive folder link', { type: 'url', placeholder: 'https://drive.google.com/...' })}
                    <Field label="Enrolled on device" htmlFor="attendance_device_id" error={form.errors.attendance_device_id} hint="Which machine they use. For information: punches link by number from any machine.">
                        <NativeSelect id="attendance_device_id" value={d.attendance_device_id} placeholder="Not set" onChange={(e) => form.setData('attendance_device_id', e.target.value)}
                            options={devices.map((x) => ({ value: x.id, label: x.label }))} />
                    </Field>
                    {text('attendance_machine_id', 'Number on the machine', {}, 'Their user number (No.) on the attendance machine.')}
                    {text('attendance_machine', 'Attendance machine note')}
                    <Field label="Notes" htmlFor="notes" error={form.errors.notes} className="sm:col-span-2">
                        <Textarea id="notes" value={d.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={3} />
                    </Field>
                </fieldset>

                {editing && employee.needs_review && (
                    <fieldset className="grid gap-3 rounded-lg border border-[#ecd29c] bg-pending-soft p-4">
                        <legend className="px-1 font-bold text-pending">Import check</legend>
                        <p className="text-sm">{employee.review_note}</p>
                        <label className="flex items-center gap-2 text-sm font-semibold">
                            <input type="checkbox" className="size-4 accent-cascade" checked={!d.needs_review} onChange={(e) => form.setData('needs_review', !e.target.checked)} />
                            Record checked: name, company and department are correct
                        </label>
                    </fieldset>
                )}

                {fromLinks && (
                    <label className="flex items-start gap-2 rounded-lg border p-4 text-sm">
                        <input type="checkbox" className="mt-0.5 size-4 accent-cascade" checked={d.start_onboarding} onChange={(e) => form.setData('start_onboarding', e.target.checked)} />
                        <span><span className="font-semibold">New hire: start onboarding</span>
                            <span className="block text-muted-foreground">Leave it off for someone who already works here. On puts them on probation for 90 days from the start date.</span></span>
                    </label>
                )}

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>{editing ? 'Save changes' : fromLinks ? 'Add and link' : 'Add employee'}</Button>
                    <Button variant="ghost" asChild><Link href={editing ? `/employees/${employee.id}` : fromLinks ? '/attendance/links?tab=numbers' : '/employees'}>Cancel</Link></Button>
                </div>
            </form>
        </>
    );
}
