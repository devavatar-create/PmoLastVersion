{{--
    Department assignment workflow (async, no page refresh) - shared by any page
    that renders cabinets.partials._assignment rows: the Cabinet detail page and
    the Dispatch list. Event listeners are delegated on `document`, so this only
    needs to be included once per page regardless of how many rows it renders.
--}}
<script>
    async function assignmentRequest(url, method, body) {
        const res = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || 'ไม่สามารถบันทึกได้ กรุณาลองใหม่');
        return data;
    }

    function applyAssignment(data) {
        const box = document.querySelector('[data-assignment="' + data.assignment.id + '"]');
        if (box) box.innerHTML = data.html;

        // Keep the Cabinet page's Edit modal department field in step with the row, if present on this page.
        const modalSelect = document.querySelector('[data-modal-department="' + data.assignment.id + '"]');
        if (modalSelect) {
            modalSelect.value = data.assignment.department_id ?? '';
            modalSelect.disabled = data.assignment.is_department_locked;
            modalSelect.title = data.assignment.is_department_locked ? 'ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากแผนกรับงานแล้ว' : '';
        }

        // "Accepted by" line on the Dispatch page, if present on this page.
        const acceptedBy = document.querySelector('[data-accepted-by="' + data.assignment.id + '"]');
        if (acceptedBy) {
            acceptedBy.textContent = data.assignment.accepted_by_name
                ? 'รับงานโดย ' + data.assignment.accepted_by_name + ' · ' + data.assignment.accepted_at
                : '';
        }

        // Lets any Alpine component on the page (e.g. the Cabinet Quick Detail
        // Panel on the Project page) keep its own reactive copy of this Sub
        // Task's assignment fields in sync, without this script needing to
        // know anything about Alpine/that panel - pure additive event, nothing
        // above this behaves differently for pages that don't listen for it.
        document.dispatchEvent(new CustomEvent('pmo:assignment-updated', { detail: data }));
    }

    /** Many sub tasks to one department in one request (rules and logs as one by one; one notice per department). */
    async function bulkDispatch(url, ids, departmentId, dueDate) {
        const data = await assignmentRequest(url, 'POST', { subtask_ids: ids, department_id: departmentId ? Number(departmentId) : null, due_date: dueDate || null });
        (data.rows || []).forEach(applyAssignment);
        window.showToast(data.message, data.changed ? 'success' : 'error');
        return data;
    }

    /** Project page: "จ่ายงานทั้งขั้น" dialog - one step of every cabinet in a template group. */
    function pmoStepDispatch(steps) {
        return {
            steps, step: null, picked: [], department: '', due: '', busy: false,
            get current() { return this.step === null ? null : this.steps[this.step]; },
            open(i) {
                this.step = i;
                this.picked = this.steps[i].parts.map((p, k) => (p.ids.length ? k : null)).filter((k) => k !== null);
            },
            ids() { return this.current ? this.picked.flatMap((k) => this.current.parts[k].ids) : []; },
            async send(url) {
                this.busy = true;
                try {
                    const data = await bulkDispatch(url, this.ids(), this.department, this.due);
                    if (data.changed) setTimeout(() => location.reload(), 600);   // the overview's numbers are worked out on the server
                    this.step = null;
                } catch (err) {
                    window.showToast(err.message, 'error');
                } finally {
                    this.busy = false;
                }
            },
        };
    }

    /** "ทวงถาม": remind the departments of work still waiting to be accepted (the server sends at most once a day). */
    async function pmoRemind(url, ids, button) {
        if (button) button.disabled = true;
        try {
            const data = await assignmentRequest(url, 'POST', { subtask_ids: ids });
            window.showToast(data.message, data.reminded ? 'success' : 'error');
        } catch (err) {
            window.showToast(err.message, 'error');
            if (button) button.disabled = false;
        }
    }

    document.addEventListener('change', async (e) => {
        if (!e.target.matches('.assignment-department-select')) return;

        const select = e.target;
        const previous = select.dataset.previous;
        select.disabled = true;

        try {
            const data = await assignmentRequest(select.dataset.url, 'PATCH', { department_id: select.value || null });
            applyAssignment(data);
            window.showToast(data.message);
        } catch (err) {
            select.value = previous;
            window.showToast(err.message, 'error');
        } finally {
            select.disabled = false;
        }
    });

    document.addEventListener('click', async (e) => {
        const button = e.target.closest('.assignment-action');
        if (!button) return;
        e.preventDefault();

        if (button.dataset.confirm && !confirm(button.dataset.confirm)) return;

        button.disabled = true;
        try {
            const data = await assignmentRequest(button.dataset.url, 'POST');
            applyAssignment(data);
            window.showToast(data.message);
        } catch (err) {
            button.disabled = false;
            window.showToast(err.message, 'error');
        }
    });
</script>
