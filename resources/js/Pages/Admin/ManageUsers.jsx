import { Head, router, useForm, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useRef, useState } from "react";
import AdminShell from "./Partials/AdminShell";

export default function ManageUsers({
  kpis = {},
  generalStaff = { data: [], links: [] },
  studentStaff = { data: [], links: [] },
  customers = { data: [], links: [] },
  filters = {},
}) {
  const { flash = {} } = usePage().props;
  const [section, setSection] = useState(filters.section || "staff");
  const [query, setQuery] = useState(filters.search || "");
  const [sort, setSort] = useState(filters.sort || "created_at");
  const [direction, setDirection] = useState(filters.direction || "desc");
  const [staffStatus, setStaffStatus] = useState(filters.staff_status || "all");
  const [customerVerification, setCustomerVerification] = useState(filters.customer_verification || "all");
  const [customerMembership, setCustomerMembership] = useState(filters.customer_membership || "all");
  const [perPage, setPerPage] = useState(String(filters.per_page || 8));

  const [staffModal, setStaffModal] = useState({ open: false, mode: "create", item: null });
  const [customerModal, setCustomerModal] = useState({ open: false, mode: "create", item: null });

  const staffImportRef = useRef(null);
  const customerImportRef = useRef(null);

  const hasStaffRows = (generalStaff?.data?.length || 0) + (studentStaff?.data?.length || 0) > 0;
  const hasCustomerRows = (customers?.data?.length || 0) > 0;

  const params = useMemo(
    () => ({
      section,
      search: query,
      sort,
      direction,
      staff_status: staffStatus,
      customer_verification: customerVerification,
      customer_membership: customerMembership,
      per_page: perPage,
    }),
    [section, query, sort, direction, staffStatus, customerVerification, customerMembership, perPage]
  );

  const changeSection = (nextSection) => {
    setSection(nextSection);
    router.get(
      route("admin.users"),
      {
        ...params,
        section: nextSection,
      },
      { preserveState: true, replace: true }
    );
  };

  const applyFilters = () => {
    router.get(route("admin.users"), params, { preserveState: true, replace: true });
  };

  const onPaginate = (url) => {
    if (!url) return;
    router.visit(url, { preserveState: true, preserveScroll: true });
  };

  const onStaffImport = (file) => {
    if (!file) return;
    router.post(route("admin.users.staff.import"), { file }, { forceFormData: true, preserveScroll: true });
  };

  const onCustomerImport = (file) => {
    if (!file) return;
    router.post(route("admin.users.customers.import"), { file }, { forceFormData: true, preserveScroll: true });
  };

  return (
    <>
      <Head title="Manage User Account" />
      <AdminShell title="Manage User Account" subtitle="CRUD, import/export, search, sorting, filters and responsive account management.">
        {(flash.success || flash.error) && (
          <div className={`mb-4 rounded-xl border px-4 py-3 text-sm font-semibold ${flash.success ? "border-emerald-200 bg-emerald-50 text-emerald-700" : "border-rose-200 bg-rose-50 text-rose-700"}`}>
            {flash.success || flash.error}
          </div>
        )}

        <section className="grid grid-cols-2 gap-3 lg:grid-cols-5">
          <KpiCard label="Active Users" value={(kpis.activeStaff || 0) + (kpis.verifiedCustomers || 0)} />
          <KpiCard label="Banned / Inactive" value={(kpis.inactiveStaff || 0) + (kpis.rejectedCustomers || 0)} />
          <KpiCard label="KYC Verified" value={kpis.verifiedCustomers} />
          <KpiCard label="KYC Unverified" value={(kpis.pendingVerification || 0) + (kpis.rejectedCustomers || 0)} />
          <KpiCard label="UiTM Members" value={kpis.uitmCustomers} />
        </section>

        <section className="mt-5 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div className="flex flex-1 flex-wrap items-end gap-2 min-w-0">
              <select
                value={section}
                onChange={(e) => changeSection(e.target.value)}
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold sm:w-auto sm:min-w-[220px]"
              >
                <option value="staff">All Staff Users ({kpis.totalStaff || 0})</option>
                <option value="customers">All Customer Users ({kpis.totalCustomers || 0})</option>
              </select>

              <input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && applyFilters()}
                className="w-full flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm sm:min-w-[260px]"
                placeholder="Search by ID, name, email, phone..."
              />

              <select
                value={sort}
                onChange={(e) => setSort(e.target.value)}
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm sm:w-auto sm:min-w-[160px]"
              >
                <option value="created_at">Registered Date</option>
                <option value="name">Name</option>
                <option value="email">Email</option>
                <option value={section === "staff" ? "staff_id" : "customer_id"}>ID</option>
              </select>

              <select
                value={direction}
                onChange={(e) => setDirection(e.target.value)}
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm sm:w-auto sm:min-w-[120px]"
              >
                <option value="desc">Desc</option>
                <option value="asc">Asc</option>
              </select>

              {section === "staff" ? (
                <select
                  value={staffStatus}
                  onChange={(e) => setStaffStatus(e.target.value)}
                  className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm sm:w-auto sm:min-w-[150px]"
                >
                  <option value="all">All Status</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              ) : (
                <>
                  <select
                    value={customerVerification}
                    onChange={(e) => setCustomerVerification(e.target.value)}
                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm sm:w-auto sm:min-w-[170px]"
                  >
                    <option value="all">All Verification</option>
                    <option value="verified">Verified</option>
                    <option value="pending">Pending</option>
                    <option value="rejected">Rejected</option>
                  </select>
                  <select
                    value={customerMembership}
                    onChange={(e) => setCustomerMembership(e.target.value)}
                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm sm:w-auto sm:min-w-[160px]"
                  >
                    <option value="all">All Membership</option>
                    <option value="uitm">UiTM</option>
                    <option value="regular">Regular</option>
                  </select>
                </>
              )}

              <select
                value={perPage}
                onChange={(e) => setPerPage(e.target.value)}
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm sm:w-auto sm:min-w-[120px]"
              >
                {[5, 8, 10, 15, 20].map((v) => <option key={v} value={v}>{v} / page</option>)}
              </select>

              <button
                onClick={applyFilters}
                className="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white sm:w-auto"
              >
                Apply
              </button>
            </div>

            <div className="flex flex-wrap gap-2 lg:justify-end shrink-0">
              <button
                onClick={() => setStaffModal({ open: true, mode: "create", item: null })}
                className="w-full whitespace-nowrap rounded-lg border-2 border-violet-900/40 bg-violet-600 px-4 py-2 text-sm font-extrabold text-white shadow-md hover:bg-violet-700 sm:w-auto"
              >
                + Add Staff
              </button>
              <button
                onClick={() => setCustomerModal({ open: true, mode: "create", item: null })}
                className="w-full whitespace-nowrap rounded-lg border-2 border-pink-900/40 bg-pink-600 px-4 py-2 text-sm font-extrabold text-white shadow-md hover:bg-pink-700 sm:w-auto"
              >
                + Add Customer
              </button>

              {section === "staff" ? (
                <button onClick={() => staffImportRef.current?.click()} className="w-full whitespace-nowrap rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50 sm:w-auto">Import Staff</button>
              ) : (
                <button onClick={() => customerImportRef.current?.click()} className="w-full whitespace-nowrap rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50 sm:w-auto">Import Customers</button>
              )}

              {section === "staff" ? (
                <button onClick={() => (window.location.href = route("admin.users.staff.export"))} className="w-full whitespace-nowrap rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50 sm:w-auto">Export Staff</button>
              ) : (
                <button onClick={() => (window.location.href = route("admin.users.customers.export"))} className="w-full whitespace-nowrap rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold hover:bg-slate-50 sm:w-auto">Export Customers</button>
              )}

              <input ref={staffImportRef} type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => onStaffImport(e.target.files?.[0])} />
              <input ref={customerImportRef} type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => onCustomerImport(e.target.files?.[0])} />
            </div>
          </div>

          {section === "staff" ? (
            <div className="mt-4 space-y-4">
              <UsersTable
                title="Full-Time Staff"
                rows={generalStaff.data || []}
                columns={["User Details", "Role", "Status", "Qualifications", "Registered Date", "Action"]}
                renderRow={(row) => (
                  <>
                    <td className="px-3 py-3"><CellName id={row.staff_id} name={row.name} email={row.email} /></td>
                    <td className="px-3 py-3">{humanize(row.role)}</td>
                    <td className="px-3 py-3"><Badge type={row.work_status === "active" ? "green" : "amber"}>{humanize(row.work_status)}</Badge></td>
                    <td className="px-3 py-3">{Number(row.qualification_count || 0)}</td>
                    <td className="px-3 py-3">{formatDate(row.created_at)}</td>
                    <td className="px-3 py-3"><StaffActions row={row} onEdit={() => setStaffModal({ open: true, mode: "edit", item: row })} /></td>
                  </>
                )}
                links={generalStaff.links || []}
                onPaginate={onPaginate}
              />

              <UsersTable
                title="Part-Time Staff"
                rows={studentStaff.data || []}
                columns={["User Details", "Role", "Status", "Working Hours", "Registered Date", "Action"]}
                renderRow={(row) => (
                  <>
                    <td className="px-3 py-3"><CellName id={row.staff_id} name={row.name} email={row.email} /></td>
                    <td className="px-3 py-3">{humanize(row.role)}</td>
                    <td className="px-3 py-3"><Badge type={row.work_status === "active" ? "green" : "amber"}>{humanize(row.work_status)}</Badge></td>
                    <td className="px-3 py-3">{row.working_hours ?? "-"}</td>
                    <td className="px-3 py-3">{formatDate(row.created_at)}</td>
                    <td className="px-3 py-3"><StaffActions row={row} onEdit={() => setStaffModal({ open: true, mode: "edit", item: row })} /></td>
                  </>
                )}
                links={studentStaff.links || []}
                onPaginate={onPaginate}
              />
            </div>
          ) : (
            <div className="mt-4 space-y-4">
              <UsersTable
                title="Customers"
                rows={customers.data || []}
                columns={["User Details", "Membership", "Email Status", "Account Status", "Registered Date", "Action"]}
                renderRow={(row) => (
                  <>
                    <td className="px-3 py-3"><CellName id={row.customer_id} name={row.name} email={row.email} /></td>
                    <td className="px-3 py-3">{row.is_uitm_member ? "UiTM" : "Regular"}</td>
                    <td className="px-3 py-3"><Badge type={row.verification_status === "verified" ? "blue" : row.verification_status === "rejected" ? "rose" : "amber"}>{humanize(row.verification_status)}</Badge></td>
                    <td className="px-3 py-3"><Badge type={row.verification_status === "verified" ? "green" : "amber"}>{row.verification_status === "verified" ? "Active" : "Inactive"}</Badge></td>
                    <td className="px-3 py-3">{formatDate(row.created_at)}</td>
                    <td className="px-3 py-3"><CustomerActions row={row} onEdit={() => setCustomerModal({ open: true, mode: "edit", item: row })} /></td>
                  </>
                )}
                links={customers.links || []}
                onPaginate={onPaginate}
              />
            </div>
          )}

          {!hasStaffRows && section === "staff" && <p className="mt-4 text-center text-sm text-slate-500">No staff records found.</p>}
          {!hasCustomerRows && section === "customers" && <p className="mt-4 text-center text-sm text-slate-500">No customer records found.</p>}
        </section>

        {staffModal.open && <StaffModal modal={staffModal} onClose={() => setStaffModal({ open: false, mode: "create", item: null })} />}
        {customerModal.open && <CustomerModal modal={customerModal} onClose={() => setCustomerModal({ open: false, mode: "create", item: null })} />}
      </AdminShell>
    </>
  );
}

function StaffActions({ row, onEdit }) {
  const toggle = () => {
    router.patch(route("admin.users.staff.status", row.staff_id), { work_status: row.work_status === "active" ? "inactive" : "active" }, { preserveScroll: true });
  };

  const remove = () => {
    if (!confirm(`Delete staff ${row.name}?`)) return;
    router.delete(route("admin.users.staff.destroy", row.staff_id), { preserveScroll: true });
  };

  return (
    <div className="flex flex-wrap gap-2">
      <button onClick={onEdit} className="rounded border border-slate-300 px-2 py-1 text-xs font-semibold">Edit</button>
      <button onClick={toggle} className="rounded border border-amber-300 px-2 py-1 text-xs font-semibold text-amber-700">{row.work_status === "active" ? "Deactivate" : "Activate"}</button>
      <button onClick={remove} className="rounded border border-rose-300 px-2 py-1 text-xs font-semibold text-rose-700">Delete</button>
    </div>
  );
}

function CustomerActions({ row, onEdit }) {
  const cycleVerification = () => {
    const next = row.verification_status === "pending" ? "verified" : row.verification_status === "verified" ? "rejected" : "pending";
    router.patch(route("admin.users.customers.verification", row.customer_id), { verification_status: next }, { preserveScroll: true });
  };
  const remove = () => {
    if (!confirm(`Delete customer ${row.name}?`)) return;
    router.delete(route("admin.users.customers.destroy", row.customer_id), { preserveScroll: true });
  };

  return (
    <div className="flex flex-wrap gap-2">
      <button onClick={onEdit} className="rounded border border-slate-300 px-2 py-1 text-xs font-semibold">Edit</button>
      <button onClick={cycleVerification} className="rounded border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700">
        {row.verification_status === "pending" ? "Mark Verified" : row.verification_status === "verified" ? "Mark Rejected" : "Mark Pending"}
      </button>
      <button onClick={remove} className="rounded border border-rose-300 px-2 py-1 text-xs font-semibold text-rose-700">Delete</button>
    </div>
  );
}

function StaffModal({ modal, onClose }) {
  const { data, setData, post, patch, processing, errors } = useForm({
    name: modal.item?.name || "",
    email: modal.item?.email || "",
    phone: modal.item?.phone || "",
    password: "",
    staff_type: modal.item?.staff_type || "general",
    role: modal.item?.role || "Staff",
    work_status: modal.item?.work_status || "active",
    working_hours: modal.item?.working_hours || 20,
  });

  useEffect(() => {
    // Auto-normalize when switching to student: keep value within allowed range.
    if (data.staff_type === "student") {
      const hours = Number(data.working_hours);
      if (!Number.isFinite(hours) || hours < 12) {
        setData("working_hours", 12);
      }
      if (Number.isFinite(hours) && hours > 40) {
        setData("working_hours", 40);
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data.staff_type]);

  const submit = (e) => {
    e.preventDefault();

    // Client-side guardrail: student staff must be minimum 12 hours.
    if (data.staff_type === "student") {
      const hours = Number(data.working_hours);
      if (!Number.isFinite(hours) || hours < 12) {
        alert("Part-Time staff must have minimum 12 working hours.");
        return;
      }
    }

    if (modal.mode === "create") {
      post(route("admin.users.staff.store"), { preserveScroll: true, onSuccess: onClose });
    } else {
      patch(route("admin.users.staff.update", modal.item.staff_id), { preserveScroll: true, onSuccess: onClose });
    }
  };

  return <ModalLayout title={`${modal.mode === "create" ? "Add" : "Edit"} Staff User`} onClose={onClose} onSubmit={submit} processing={processing} errors={errors} data={data} setData={setData} isStaff isCreate={modal.mode === "create"} />;
}

function CustomerModal({ modal, onClose }) {
  const { data, setData, post, patch, processing, errors } = useForm({
    name: modal.item?.name || "",
    email: modal.item?.email || "",
    phone: modal.item?.phone || "",
    password: "",
    verification_status: modal.item?.verification_status || "pending",
    is_uitm_member: !!modal.item?.is_uitm_member,
  });

  const submit = (e) => {
    e.preventDefault();
    if (modal.mode === "create") {
      post(route("admin.users.customers.store"), { preserveScroll: true, onSuccess: onClose });
    } else {
      patch(route("admin.users.customers.update", modal.item.customer_id), { preserveScroll: true, onSuccess: onClose });
    }
  };

  return <ModalLayout title={`${modal.mode === "create" ? "Add" : "Edit"} Customer User`} onClose={onClose} onSubmit={submit} processing={processing} errors={errors} data={data} setData={setData} isCreate={modal.mode === "create"} />;
}

function ModalLayout({ title, onClose, onSubmit, processing, errors, data, setData, isStaff = false, isCreate = false }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <form onSubmit={onSubmit} className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl max-h-screen overflow-y-auto">
        <h3 className="text-lg font-extrabold text-slate-800">{title}</h3>
        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Name" value={data.name} onChange={(v) => setData("name", v)} error={errors.name} />
          <Field label="Email" type="email" value={data.email} onChange={(v) => setData("email", v)} error={errors.email} />
          <Field label="Phone" value={data.phone} onChange={(v) => setData("phone", v)} error={errors.phone} />
          <Field label={isCreate ? "Password" : "Password (leave blank to keep)"} type="password" value={data.password} onChange={(v) => setData("password", v)} error={errors.password} />
          {isCreate && data.password && <PasswordRequirementsHint password={data.password} />}

          {isStaff ? (
            <>
              <label className="text-sm">
                <span className="mb-1 block font-medium text-slate-700">Staff Type</span>
                <select value={data.staff_type} onChange={(e) => setData("staff_type", e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2">
                  <option value="general">Full-Time Staff</option>
                  <option value="student">Part-Time Staff</option>
                </select>
              </label>
              <Field label="Role" value={data.role} onChange={(v) => setData("role", v)} error={errors.role} />
              <SelectField label="Work Status" value={data.work_status} onChange={(v) => setData("work_status", v)} options={["active", "inactive"]} />
              {data.staff_type === "student" && (
                <Field
                  label="Working Hours (Min 12)"
                  type="number"
                  value={data.working_hours}
                  onChange={(v) => setData("working_hours", v)}
                  error={errors.working_hours}
                  min={12}
                  max={40}
                  step={1}
                  required
                />
              )}
            </>
          ) : (
            <>
              <SelectField label="Verification" value={data.verification_status} onChange={(v) => setData("verification_status", v)} options={["pending", "verified", "rejected"]} />
              <label className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium">
                <span className="mb-2 block">UiTM Member</span>
                <input type="checkbox" checked={!!data.is_uitm_member} onChange={(e) => setData("is_uitm_member", e.target.checked)} />
              </label>
            </>
          )}
        </div>

        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Cancel</button>
          <button disabled={processing} className="rounded-lg bg-violet-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">Save</button>
        </div>
      </form>
    </div>
  );
}

function PasswordRequirementsHint({ password }) {
  const checks = [
    { label: "8+ characters", ok: password.length >= 8 },
    { label: "Uppercase (A-Z)", ok: /[A-Z]/.test(password) },
    { label: "Number (0-9)", ok: /[0-9]/.test(password) },
    { label: "Symbol (!@#…)", ok: /[!@#$%^&*()_+\-=[\]{};':"\\|,.<>/?]/.test(password) },
  ];
  if (checks.every((c) => c.ok)) return null;
  return (
    <div className="col-span-full rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs">
      <p className="mb-1 font-semibold text-amber-700">Password must include:</p>
      <div className="flex flex-wrap gap-x-4 gap-y-1">
        {checks.map((c) => (
          <span key={c.label} className={c.ok ? "text-emerald-600" : "text-amber-700"}>
            {c.ok ? "✓" : "○"} {c.label}
          </span>
        ))}
      </div>
    </div>
  );
}

function UsersTable({ title, rows, columns, renderRow, links, onPaginate }) {
  return (
    <div className="rounded-xl border border-slate-200">
      <div className="border-b border-slate-200 px-4 py-3 text-sm font-bold text-slate-700">{title}</div>
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50 text-left text-slate-500">
            <tr>{columns.map((c) => <th key={c} className="px-3 py-2 font-semibold">{c}</th>)}</tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.staff_id || row.customer_id} className="border-t border-slate-100">{renderRow(row)}</tr>
            ))}
          </tbody>
        </table>
      </div>
      {!!links?.length && (
        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-3">
          {links.map((l, i) => (
            <button
              key={`${l.label}-${i}`}
              disabled={!l.url}
              onClick={() => onPaginate(l.url)}
              className={`rounded px-2 py-1 text-xs ${l.active ? "bg-slate-900 text-white" : "border border-slate-200"} disabled:opacity-40`}
              dangerouslySetInnerHTML={{ __html: l.label }}
            />
          ))}
        </div>
      )}
    </div>
  );
}

function Field({ label, value, onChange, error, type = "text", ...inputProps }) {
  const [showPw, setShowPw] = useState(false);
  const isPassword = type === "password";
  return (
    <div className="text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <div className="relative">
        <input
          type={isPassword ? (showPw ? "text" : "password") : type}
          value={value ?? ""}
          onChange={(e) => onChange(e.target.value)}
          className={`w-full rounded-lg border border-slate-200 px-3 py-2 ${isPassword ? "pr-9" : ""}`}
          {...inputProps}
        />
        {isPassword && (
          <button
            type="button"
            tabIndex={-1}
            onClick={() => setShowPw((v) => !v)}
            className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 focus:outline-none"
            aria-label={showPw ? "Hide password" : "Show password"}
          >
            {showPw ? (
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.769m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
              </svg>
            ) : (
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
            )}
          </button>
        )}
      </div>
      {error && <span className="text-xs text-rose-600">{error}</span>}
    </div>
  );
}

function SelectField({ label, value, onChange, options }) {
  return (
    <label className="text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <select value={value} onChange={(e) => onChange(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2">
        {options.map((v) => (
          <option key={v} value={v}>{humanize(v)}</option>
        ))}
      </select>
    </label>
  );
}

function KpiCard({ label, value }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-extrabold text-slate-800">{Number(value || 0)}</p>
    </div>
  );
}

function Badge({ type = "green", children }) {
  const styles = {
    green: "bg-emerald-100 text-emerald-700",
    amber: "bg-amber-100 text-amber-700",
    rose: "bg-rose-100 text-rose-700",
    blue: "bg-blue-100 text-blue-700",
  };
  return <span className={`rounded-full px-3 py-1 text-xs font-bold ${styles[type]}`}>{children}</span>;
}

function CellName({ id, name, email }) {
  return (
    <div>
      <p className="font-semibold text-slate-700">#{id} {name || "-"}</p>
      <p className="text-xs text-slate-500">{email || "-"}</p>
    </div>
  );
}

function humanize(value) {
  return String(value || "-").replace(/[_-]+/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(v) {
  if (!v) return "-";
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return String(v);
  return d.toLocaleDateString("en-MY", { day: "2-digit", month: "long", year: "numeric" });
}
