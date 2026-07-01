import React, { useEffect, useMemo, useState } from "react";
import { usePage } from "@inertiajs/react";
import CustomerLayout from "@/Layouts/CustomerLayout";

export default function Profile({ auth, stats = {}, status }) {
  const customer = auth?.user || {};
  const { flash } = usePage().props;

  const [isEditing, setIsEditing] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [errors, setErrors] = useState({});
  const [formData, setFormData] = useState({
    name: customer.name || "",
    email: customer.email || "",
    phone: customer.phone || "",
  });

  const quickStats = useMemo(
    () => [
      { icon: "fas fa-users", label: "Happy Customers", value: `${stats?.totalCustomers ?? 0}+` },
      { icon: "fas fa-user-graduate", label: "Student Therapists", value: stats?.studentStaff ?? 0 },
      { icon: "fas fa-briefcase", label: "Professional Staff", value: stats?.generalStaff ?? 0 },
      { icon: "fas fa-star", label: "Average Rating", value: Number(stats?.avgRating ?? 0).toFixed(1) },
    ],
    [stats]
  );

  useEffect(() => {
    const text = status || flash?.success || flash?.error;
    if (!text) return;
    setMessage(String(text));
    const timer = setTimeout(() => setMessage(""), 3500);
    return () => clearTimeout(timer);
  }, [status, flash?.success, flash?.error]);

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
    if (errors[name]) setErrors((prev) => ({ ...prev, [name]: "" }));
  };

  const handleCancel = () => {
    setFormData({
      name: customer.name || "",
      email: customer.email || "",
      phone: customer.phone || "",
    });
    setErrors({});
    setIsEditing(false);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setIsLoading(true);
    setErrors({});
    setMessage("");

    try {
      const response = await fetch("/customer/profile", {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.content,
        },
        body: JSON.stringify(formData),
      });

      const data = await response.json();
      if (data?.ok) {
        setMessage(data.message || "Profile updated successfully.");
        setIsEditing(false);
      } else if (data?.errors) {
        setErrors(data.errors);
      } else {
        setMessage(data?.message || "Failed to update profile. Please try again.");
      }
    } catch (err) {
      setMessage("Failed to update profile. Please try again.");
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <CustomerLayout title="My Profile" active="profile">
      <main className="mx-auto w-full max-w-7xl px-4 pb-10 pt-6 sm:px-6 lg:px-8 lg:pt-10">
        {message ? (
          <div className="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
            {message}
          </div>
        ) : null}

        <div className="mb-8 text-center">
          <h1 className="text-3xl font-black text-slate-900 sm:text-4xl">My Profile</h1>
          <p className="mt-2 text-sm font-medium text-slate-600 sm:text-base">
            Manage your account information and preferences
          </p>
        </div>

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1.6fr_1fr] xl:gap-8">
          <section className="rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-xl backdrop-blur sm:p-7">
            <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <h2 className="text-2xl font-black text-slate-900">Personal Information</h2>
              {!isEditing ? (
                <button
                  onClick={() => setIsEditing(true)}
                  className="inline-flex items-center justify-center gap-2 rounded-full bg-slate-900 px-5 py-2.5 text-sm font-extrabold text-white hover:opacity-90"
                  type="button"
                >
                  <i className="fas fa-pen" /> Edit Profile
                </button>
              ) : null}
            </div>

            <form onSubmit={handleSubmit} className="space-y-5">
              <Field
                label="Full Name"
                icon="fas fa-user"
                editing={isEditing}
                name="name"
                value={isEditing ? formData.name : customer.name || "Not provided"}
                onChange={handleInputChange}
                error={errors.name}
                type="text"
              />

              <Field
                label="Email Address"
                icon="fas fa-envelope"
                editing={isEditing}
                name="email"
                value={isEditing ? formData.email : customer.email || "Not provided"}
                onChange={handleInputChange}
                error={errors.email}
                type="email"
              />

              <Field
                label="Phone Number"
                icon="fas fa-phone"
                editing={isEditing}
                name="phone"
                value={isEditing ? formData.phone : customer.phone || "Not provided"}
                onChange={handleInputChange}
                error={errors.phone}
                type="tel"
              />

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <InfoBadge
                  icon="fas fa-id-badge"
                  label="Member Type"
                  value={customer.is_uitm_member ? "UiTM Student Member" : "Regular Member"}
                />
                <InfoBadge
                  icon="fas fa-shield-alt"
                  label="Verification"
                  value={customer.verification_status === "verified" ? "Verified" : "Pending"}
                />
              </div>

              {isEditing ? (
                <div className="flex flex-col gap-3 pt-2 sm:flex-row">
                  <button
                    type="submit"
                    disabled={isLoading}
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 py-3 text-sm font-extrabold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    {isLoading ? <i className="fas fa-spinner fa-spin" /> : <i className="fas fa-save" />}
                    {isLoading ? "Saving..." : "Save Changes"}
                  </button>
                  <button
                    type="button"
                    onClick={handleCancel}
                    disabled={isLoading}
                    className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-extrabold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    <i className="fas fa-times" /> Cancel
                  </button>
                </div>
              ) : null}
            </form>
          </section>

          <aside className="space-y-5">
            <section className="rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-xl backdrop-blur sm:p-6">
              <h3 className="text-xl font-black text-slate-900">Uni-Spa Statistics</h3>
              <div className="mt-4 space-y-3">
                {quickStats.map((item) => (
                  <div key={item.label} className="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 p-3">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-900 text-white">
                      <i className={item.icon} />
                    </div>
                    <div>
                      <p className="text-lg font-black text-slate-900 leading-none">{item.value}</p>
                      <p className="text-sm font-semibold text-slate-600">{item.label}</p>
                    </div>
                  </div>
                ))}
              </div>
            </section>

            <section className="rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-xl backdrop-blur sm:p-6">
              <h3 className="text-base font-black text-slate-900">Profile Benefits</h3>
              <ul className="mt-3 space-y-2 text-sm font-semibold text-slate-600">
                <li>• Manage your personal information</li>
                <li>• Track your appointment history</li>
                <li>• Receive exclusive offers</li>
                <li>• Get faster future booking experience</li>
              </ul>
            </section>
          </aside>
        </div>
      </main>
    </CustomerLayout>
  );
}

function Field({ label, icon, editing, name, value, onChange, error, type }) {
  return (
    <div>
      <label className="mb-1.5 block text-sm font-bold text-slate-700">{label}</label>
      {editing ? (
        <input
          type={type}
          name={name}
          value={value}
          onChange={onChange}
          className={`w-full rounded-xl border bg-white px-4 py-3 text-sm font-semibold text-slate-800 outline-none transition ${
            error ? "border-rose-400 focus:border-rose-500" : "border-slate-300 focus:border-slate-600"
          }`}
        />
      ) : (
        <div className="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
          <i className={`${icon} text-slate-500`} />
          <span>{value}</span>
        </div>
      )}
      {error ? <p className="mt-1 text-xs font-bold text-rose-600">{error}</p> : null}
    </div>
  );
}

function InfoBadge({ icon, label, value }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
      <p className="text-xs font-black uppercase tracking-wide text-slate-500">
        <i className={`${icon} mr-2`} />
        {label}
      </p>
      <p className="mt-1 text-sm font-bold text-slate-800">{value}</p>
    </div>
  );
}