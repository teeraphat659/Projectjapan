document.addEventListener("DOMContentLoaded", function () {
  const backendMarker = "/backend/";
  const currentPath = window.location.pathname;
  const projectBase = currentPath.includes(backendMarker)
    ? currentPath.slice(0, currentPath.indexOf(backendMarker))
    : "";
  const appUrl = (path) => `${projectBase}/${path.replace(/^\/+/, "")}`;

  document.querySelectorAll("[href], [src], [action]").forEach((element) => {
    ["href", "src", "action"].forEach((attribute) => {
      const value = element.getAttribute(attribute);
      if (value && value.startsWith("/projectjapan/")) {
        element.setAttribute(attribute, projectBase + value.slice("/projectjapan".length));
      }
    });
  });

  console.log("กำลังเริ่มโหลด Navbar..."); // เช็คว่าสคริปต์ทำงานไหม

  fetch(appUrl("backend/navbar/navbarbackend.html"))
    .then((response) => {
      if (!response.ok) throw new Error("ไม่พบไฟล์ Navbar (HTTP " + response.status + ")");
      return response.text();
    })
    .then((data) => {
      const container = document.getElementById("navbar-container");
      if (!container) {
        console.error("❌ พังตรงนี้: หา <div id='navbar-container'></div> ในไฟล์หลักไม่พบ!");
        return;
      }
      
      container.innerHTML = data.replaceAll("/projectjapan/", `${projectBase}/`);
      console.log("✅ โหลด Navbar ลงหน้าเว็บสำเร็จ");

      fetch(appUrl("Front-end/connectapi/nav_state.php"), { credentials: "same-origin" })
        .then((response) => response.json())
        .then((state) => {
          if (state.role !== "admin") return;
          const name = state.username || "ผู้ดูแลระบบ";
          const navName = document.getElementById("adminNavName");
          const displayName = document.getElementById("adminDisplayName");
          const email = document.getElementById("adminEmail");
          if (navName) navName.textContent = name;
          if (displayName) displayName.textContent = name;
          if (email) email.textContent = state.email || "-";
        })
        .catch(() => {});

      // Active Nav Link
      const currentPath = window.location.pathname;
      document.querySelectorAll(".nav-item").forEach((link) => {
        if (link.getAttribute("href") === currentPath) {
          link.classList.add("active");
        }
      });

      // จัดการ Dropdown
      const profileBtn = document.getElementById("profileBtn");
      const profileDropdown = document.getElementById("profileDropdown");
      const profileMenu = document.getElementById("profileMenu");

      console.log("เช็คปุ่ม Profile (ถ้าขึ้น null แปลว่าผิด):", profileBtn);

      if (profileBtn && profileDropdown) {
        profileBtn.addEventListener("click", function (e) {
          e.stopPropagation();
          console.log("🔥 มีการคลิกปุ่ม Profile แล้ว!"); // เช็คว่าคลิกติดไหม
          
          profileDropdown.classList.toggle("active");
          if (profileMenu) profileMenu.classList.toggle("show");
        });

        // คลิกข้างนอกเพื่อปิด
        document.addEventListener("click", function (e) {
          if (!profileDropdown.contains(e.target)) {
            profileDropdown.classList.remove("active");
            if (profileMenu) profileMenu.classList.remove("show");
          }
        });
      } else {
        console.error("❌ พังตรงนี้: หา id='profileBtn' ใน HTML ของ Navbar ไม่พบ");
      }
    })
    .catch((error) => console.error("❌ Error loading navbar:", error));
});
