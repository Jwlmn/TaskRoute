<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../services/api'

const router = useRouter()
const loading = ref(false)
const captchaImage = ref('')
const form = reactive({
  account: '',
  password: '',
  captcha_key: '',
  captcha_code: '',
})

const fetchCaptcha = async () => {
  const { data } = await api.get('/auth/captcha')
  captchaImage.value = data.image
  form.captcha_key = data.key
  form.captcha_code = ''
}

const submit = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/auth/login', form)
    localStorage.setItem('taskroute_token', data.token)
    localStorage.setItem('taskroute_user', JSON.stringify(data.user))
    ElMessage.success('登录成功')
    await router.push({ name: 'dashboard-home' })
  } catch (error) {
    const message = error?.response?.data?.message || '登录失败，请检查输入'
    ElMessage.error(message)
    await fetchCaptcha()
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchCaptcha()
})
</script>

<template>
  <div class="login-page">
    <el-card class="login-card" shadow="hover">
      <template #header>
        <div class="login-title">TaskRoute 统一门户登录</div>
      </template>
      <el-form label-position="top" @submit.prevent="submit">
        <el-form-item label="账号">
          <el-input v-model="form.account" placeholder="请输入管理员分配的账号" />
        </el-form-item>
        <el-form-item label="密码">
          <el-input v-model="form.password" type="password" show-password placeholder="请输入密码" />
        </el-form-item>
        <el-form-item label="图片验证码">
          <div class="captcha-row">
            <el-input v-model="form.captcha_code" placeholder="请输入结果，如 8" />
            <img :src="captchaImage" class="captcha-image" alt="captcha" @click="fetchCaptcha" />
          </div>
          <div class="captcha-tip">点击验证码图片可刷新</div>
        </el-form-item>
        <el-button type="primary" :loading="loading" style="width: 100%" @click="submit">
          登录
        </el-button>
      </el-form>
      <div class="login-tip">系统不开放注册，由管理员统一分配账号。</div>
    </el-card>
  </div>
</template>
