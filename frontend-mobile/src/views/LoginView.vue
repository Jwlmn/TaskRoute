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
  client_type: 'mobile',
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
    await router.push({ name: 'mobile-home' })
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '登录失败')
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
  <div class="mobile-login">
    <div class="mobile-login-card">
      <div class="mobile-login-title">TaskRoute 移动端登录</div>
      <el-form label-position="top" @submit.prevent="submit">
        <el-form-item label="账号">
          <el-input v-model="form.account" placeholder="请输入账号" />
        </el-form-item>
        <el-form-item label="密码">
          <el-input v-model="form.password" type="password" show-password placeholder="请输入密码" />
        </el-form-item>
        <el-form-item label="图片验证码">
          <div class="mobile-captcha-row">
            <el-input v-model="form.captcha_code" placeholder="验证码结果" />
            <img :src="captchaImage" class="mobile-captcha-image" alt="captcha" @click="fetchCaptcha" />
          </div>
        </el-form-item>
        <el-button type="primary" :loading="loading" class="mobile-submit" @click="submit">
          登录
        </el-button>
      </el-form>
    </div>
  </div>
</template>
